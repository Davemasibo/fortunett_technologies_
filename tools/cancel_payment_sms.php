<?php
/** CLI only: retain cancelled payment notices so callback replays cannot requeue them. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['tenant:', 'before:', 'apply']);
$tenant = filter_var($options['tenant'] ?? null, FILTER_VALIDATE_INT);
$before = $options['before'] ?? '';
$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $before, new DateTimeZone('Africa/Nairobi'));
if (!$tenant || $tenant < 1 || !$date || $date->format('Y-m-d H:i:s') !== $before) {
    fwrite(STDERR, "Usage: php tools/cancel_payment_sms.php --tenant=ID --before=\"YYYY-MM-DD HH:MM:SS\" [--apply]\nCutoff uses Africa/Nairobi time. Without --apply, only counts are shown.\n");
    exit(1);
}
require_once __DIR__ . '/../includes/db_master.php';
// Compare payment time, not retry/update time: old notices may have just been retried.
$where = "n.tenant_id=? AND p.payment_date<? AND n.status IN ('pending','failed','blocked_config')";
$join = 'payment_notifications n JOIN payments p ON p.id=n.payment_id AND p.tenant_id=n.tenant_id';
$st = $pdo->prepare("SELECT n.status, COUNT(*) AS total FROM $join WHERE $where GROUP BY n.status");
$st->execute([$tenant, $before]);
echo "Tenant $tenant: payment SMS before $before (Africa/Nairobi)\n";
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) echo $row['status'] . ': ' . $row['total'] . "\n";
if (!isset($options['apply'])) { echo "Preview only. Add --apply to cancel these unsent notifications.\n"; exit; }
$st = $pdo->prepare("UPDATE $join SET n.status='cancelled',n.last_error=? WHERE $where");
$st->execute(['Historical payment SMS cancelled by operator; cutoff ' . $before, $tenant, $before]);
echo 'Cancelled: ' . $st->rowCount() . "\nSent, sending and unknown records are preserved. Already submitted messages cannot be recalled.\n";
