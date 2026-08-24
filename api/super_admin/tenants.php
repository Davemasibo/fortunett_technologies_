<?php
/**
 * Super Admin — Tenant Management API
 * POST /api/super_admin/tenants.php
 * Actions: set_status | save_notes | set_plan | extend_subscription | extend_days
 *          | mark_invoice_paid | settle_all_invoices
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/schema_guard.php';
require_once __DIR__ . '/../../super_admin/includes/auth.php';

/**
 * Add whole months without the Jan-31 → Mar-3 overflow PHP's "+1 month" gives.
 * Clamps the day to the last day of the target month; keeps the time of day.
 */
function _addMonthsClamped(DateTimeImmutable $d, int $n): DateTimeImmutable
{
    $firstOfTarget = $d->modify("first day of +{$n} month");
    $day = min((int)$d->format('j'), (int)$firstOfTarget->format('t'));
    return $firstOfTarget->setDate(
        (int)$firstOfTarget->format('Y'),
        (int)$firstOfTarget->format('n'),
        $day
    );
}

/** "3 hours", "1 day", "2 months 4 days" — how long until $when. */
function _untilHuman(DateTimeImmutable $when, DateTimeImmutable $now): string
{
    $diff = $now->diff($when);
    $bits = [];
    if ($diff->y) $bits[] = $diff->y . ' year'  . ($diff->y > 1 ? 's' : '');
    if ($diff->m) $bits[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    if ($diff->d) $bits[] = $diff->d . ' day'   . ($diff->d > 1 ? 's' : '');
    if (!$diff->y && !$diff->m && $diff->h) $bits[] = $diff->h . ' hour'   . ($diff->h > 1 ? 's' : '');
    if (!$diff->y && !$diff->m && !$diff->d && $diff->i) $bits[] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
    if (!$bits) $bits[] = 'less than a minute';
    return implode(' ', array_slice($bits, 0, 2));
}

if (!isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action   = $data['action']    ?? '';
$tenantId = (int)($data['tenant_id'] ?? 0);

if (!$tenantId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'tenant_id required']);
    exit;
}

// Confirm tenant exists
$check = $pdo->prepare("SELECT id, company_name FROM tenants WHERE id = ?");
$check->execute([$tenantId]);
$tenant = $check->fetch();
if (!$tenant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Tenant not found']);
    exit;
}

try {
    switch ($action) {

        case 'set_status':
            $allowed = ['active', 'suspended', 'trial', 'expired'];
            $status  = $data['status'] ?? '';
            if (!in_array($status, $allowed, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status']);
                exit;
            }

            $cols  = ['status = ?'];
            $vals  = [$status];

            if ($status === 'suspended') {
                $cols[] = 'suspended_at = NOW()';
                $cols[] = 'suspended_reason = ?';
                $vals[] = $data['reason'] ?? 'Manually suspended by super admin';
            } elseif ($status === 'active') {
                $cols[] = 'suspended_at = NULL';
                $cols[] = 'suspended_reason = NULL';
            }

            $vals[] = $tenantId;
            $pdo->prepare("UPDATE tenants SET " . implode(', ', $cols) . " WHERE id = ?")
                ->execute($vals);

            echo json_encode([
                'success' => true,
                'message' => "Tenant {$tenant['company_name']} status updated to $status"
            ]);
            break;

        case 'save_notes':
            $notes = substr(trim($data['notes'] ?? ''), 0, 2000);
            $pdo->prepare("UPDATE tenants SET notes = ? WHERE id = ?")
                ->execute([$notes, $tenantId]);
            echo json_encode(['success' => true, 'message' => 'Notes saved']);
            break;

        case 'set_plan':
            $planId = (int)($data['plan_id'] ?? 0);
            $plan = $pdo->prepare("SELECT id FROM platform_subscription_plans WHERE id = ? AND is_active = 1");
            $plan->execute([$planId]);
            if (!$plan->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Invalid plan']);
                exit;
            }
            $pdo->prepare("UPDATE tenants SET subscription_plan_id = ? WHERE id = ?")
                ->execute([$planId, $tenantId]);
            echo json_encode(['success' => true, 'message' => 'Plan updated']);
            break;

        // Legacy shim — the old UI only ever sent whole days.
        case 'extend_days':
            $data['unit']   = 'days';
            $data['amount'] = (int)($data['days'] ?? 0);
            // fall through

        case 'extend_subscription':
            // Hour precision only exists once the DATE columns are DATETIME.
            ensureTenantExpiryPrecision($pdo);

            $now = new DateTimeImmutable('now');

            $tRow = $pdo->prepare("SELECT status, trial_ends_at, subscription_ends_at FROM tenants WHERE id = ?");
            $tRow->execute([$tenantId]);
            $tRow = $tRow->fetch(PDO::FETCH_ASSOC);

            // A tenant still on trial keeps their trial clock; everyone else —
            // active, suspended, expired — is on the subscription clock, which is
            // what requireTenantActive() bounces them off with subscription_expired=1.
            $isTrial = ($tRow['status'] === 'trial');
            $field   = $isTrial ? 'trial_ends_at' : 'subscription_ends_at';
            // A zero date or anything unparseable counts as "no expiry set" —
            // never as a base to extend from, which would throw.
            $current   = $tRow[$field] ?: null;
            $currentDt = null;
            if ($current && strncmp((string)$current, '0000-00-00', 10) !== 0) {
                try { $currentDt = new DateTimeImmutable($current); } catch (Throwable $e) { $currentDt = null; }
            }

            // 'until' sets an exact moment; otherwise add a period. 'from_now'
            // restarts the clock instead of stacking onto unused time.
            if (!empty($data['until'])) {
                $ts = strtotime((string)$data['until']);
                if ($ts === false) {
                    echo json_encode(['success' => false, 'message' => 'Could not read that date/time']);
                    exit;
                }
                $new = (new DateTimeImmutable())->setTimestamp($ts);
                if ($new <= $now) {
                    echo json_encode(['success' => false, 'message' => 'That date is in the past — pick a future one']);
                    exit;
                }
                $applied  = 'an exact end date';
                $headline = 'Access end date set';
            } else {
                $unit   = strtolower(trim((string)($data['unit'] ?? '')));
                $amount = (int)($data['amount'] ?? 0);

                $limits = ['hours' => 8760, 'days' => 3650, 'months' => 120];
                // Tolerate singulars from hand-written calls
                $unit = rtrim($unit, 's') . 's';
                if (!isset($limits[$unit])) {
                    echo json_encode(['success' => false, 'message' => 'unit must be hours, days or months']);
                    exit;
                }
                if ($amount < 1 || $amount > $limits[$unit]) {
                    echo json_encode(['success' => false, 'message' => "amount must be between 1 and {$limits[$unit]} $unit"]);
                    exit;
                }

                // Start from the existing expiry when it is still in the future so
                // unused time is not thrown away; otherwise start from now, or the
                // extension would land in the past and change nothing.
                $fromNow = !empty($data['from_now']);
                $base = (!$fromNow && $currentDt && $currentDt > $now) ? $currentDt : $now;

                $new = ($unit === 'months')
                     ? _addMonthsClamped($base, $amount)
                     : $base->modify("+{$amount} {$unit}");

                $applied  = $amount . ' ' . ($amount === 1 ? rtrim($unit, 's') : $unit);
                $headline = 'Access extended by ' . $applied;
            }

            $newValue = $new->format('Y-m-d H:i:s');

            if ($isTrial) {
                $pdo->prepare("UPDATE tenants SET trial_ends_at = ?, status = 'trial' WHERE id = ?")
                    ->execute([$newValue, $tenantId]);
                $newStatus = 'trial';
            } else {
                // A tenant parked on suspended/expired stays walled off no matter
                // how far out the date goes, so lift that too.
                $newStatus = in_array($tRow['status'], ['suspended', 'expired'], true) ? 'active' : $tRow['status'];
                $pdo->prepare("
                    UPDATE tenants
                    SET subscription_ends_at = ?, status = ?, suspended_at = NULL, suspended_reason = NULL
                    WHERE id = ?
                ")->execute([$newValue, $newStatus, $tenantId]);
            }

            error_log(sprintf(
                '[super_admin] tenant #%d %s extended by %s to %s by %s',
                $tenantId, $field, $applied, $newValue, $_SESSION['username'] ?? 'super admin'
            ));

            // Extending a date does not clear what they owe, and
            // check_suspensions.php suspends on OUTSTANDING INVOICES rather than
            // dates — so say plainly that this grace has an expiry of its own.
            $warning = null;
            try {
                $owed = $pdo->prepare("SELECT COUNT(*) FROM platform_invoices WHERE tenant_id = ? AND status <> 'paid'");
                $owed->execute([$tenantId]);
                if ((int)$owed->fetchColumn() > 0) {
                    $warning = 'This tenant still has outstanding invoices — the daily suspension check '
                             . 'will re-suspend them. Settle or waive the invoices to make this stick.';
                }
            } catch (Throwable $e) {
                error_log('extend_subscription invoice check: ' . $e->getMessage());
            }

            echo json_encode([
                'success'    => true,
                'message'    => "{$headline} — {$tenant['company_name']} now runs until "
                              . $new->format('D d M Y, H:i') . ' (' . _untilHuman($new, $now) . ' from now)',
                'warning'    => $warning,
                'field'      => $field,
                'new_date'   => $newValue,
                'new_status' => $newStatus,
            ]);
            break;

        case 'mark_invoice_paid':
            $invoiceId = (int)($data['invoice_id'] ?? 0);
            $ref       = trim($data['transaction_ref'] ?? '');
            if (!$invoiceId) {
                echo json_encode(['success' => false, 'message' => 'invoice_id required']);
                exit;
            }
            $upd = $pdo->prepare("
                UPDATE platform_invoices
                SET status = 'paid', paid_at = NOW(), payment_method = 'manual', transaction_ref = ?
                WHERE id = ? AND tenant_id = ?
            ");
            $upd->execute([$ref ?: 'MANUAL-' . strtoupper(bin2hex(random_bytes(3))), $invoiceId, $tenantId]);

            if ($upd->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Invoice not found for this tenant']);
                exit;
            }

            // Settling the last outstanding invoice should restore service now, not
            // whenever check_suspensions.php next runs. Waiting up to 24h after the
            // operator has confirmed payment is the sort of delay that generates
            // support calls.
            $message = 'Invoice marked as paid';
            try {
                $out = $pdo->prepare("
                    SELECT COUNT(*) FROM platform_invoices
                    WHERE tenant_id = ? AND status <> 'paid'
                ");
                $out->execute([$tenantId]);
                $stillOwing = (int)$out->fetchColumn();

                if ($stillOwing === 0) {
                    $st = $pdo->prepare("SELECT status FROM tenants WHERE id = ? LIMIT 1");
                    $st->execute([$tenantId]);
                    if ($st->fetchColumn() === 'suspended') {
                        $pdo->prepare("
                            UPDATE tenants
                            SET status = 'active', suspended_at = NULL, suspended_reason = NULL
                            WHERE id = ?
                        ")->execute([$tenantId]);
                        $message = 'Invoice marked as paid — tenant reactivated (no outstanding invoices)';
                    }
                } else {
                    $message = "Invoice marked as paid — {$stillOwing} invoice(s) still outstanding";
                }
            } catch (Throwable $e) {
                error_log('mark_invoice_paid reactivation check: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'message' => $message]);
            break;

        case 'settle_all_invoices':
            // Breaks the activate/re-suspend loop. Setting a tenant back to
            // 'active' never worked on its own, because check_suspensions.php
            // suspends on OUTSTANDING INVOICES, not on dates — so the next cron
            // run undid it. Clearing what they owe is the only thing that sticks.
            $ref  = trim($data['transaction_ref'] ?? '');
            $mode = ($data['mode'] ?? 'paid') === 'waived' ? 'waived' : 'paid';

            $sel = $pdo->prepare("SELECT id, total_due FROM platform_invoices WHERE tenant_id = ? AND status <> 'paid'");
            $sel->execute([$tenantId]);
            $open = $sel->fetchAll(PDO::FETCH_ASSOC);

            if (!$open) {
                echo json_encode(['success' => true, 'message' => 'Nothing outstanding — this tenant owes nothing']);
                exit;
            }

            $total  = 0.0;
            $prefix = $mode === 'waived' ? 'WAIVED-' : 'MANUAL-';
            $upd = $pdo->prepare("
                UPDATE platform_invoices
                SET status = 'paid', paid_at = NOW(), payment_method = ?, transaction_ref = ?
                WHERE id = ? AND tenant_id = ?
            ");
            foreach ($open as $inv) {
                $total += (float)$inv['total_due'];
                $upd->execute([
                    $mode === 'waived' ? 'waiver' : 'manual',
                    $ref ?: $prefix . strtoupper(bin2hex(random_bytes(3))),
                    $inv['id'],
                    $tenantId,
                ]);
            }

            $reactivated = false;
            try {
                $st = $pdo->prepare("SELECT status FROM tenants WHERE id = ? LIMIT 1");
                $st->execute([$tenantId]);
                if (in_array($st->fetchColumn(), ['suspended', 'expired'], true)) {
                    $pdo->prepare("
                        UPDATE tenants
                        SET status = 'active', suspended_at = NULL, suspended_reason = NULL
                        WHERE id = ?
                    ")->execute([$tenantId]);
                    $reactivated = true;
                }
            } catch (Throwable $e) {
                error_log('settle_all_invoices reactivation: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => count($open) . " invoice(s) totalling KSH " . number_format($total, 2)
                           . " marked {$mode}" . ($reactivated ? ' — tenant reactivated' : ''),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }

} catch (PDOException $e) {
    error_log("super_admin/tenants.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
