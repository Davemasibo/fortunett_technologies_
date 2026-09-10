<?php
/** Offline regression checks: no database, payment gateway or router connection. */
require_once __DIR__ . '/../includes/payment_activation.php';
require_once __DIR__ . '/../includes/auto_provision.php';
require_once __DIR__ . '/../includes/payment_terms.php';

class ConnectivityTestPDO extends PDO
{
    public array $client;
    public array $activations = [];
    public array $queue = [];
    public bool $failQueue = false;
    public array $purchaseTerms = [];
    public array $package = ['id' => 9, 'price' => 20, 'validity_value' => 30, 'validity_unit' => 'minutes'];
    private ?array $snapshot = null;
    public function __construct() {
        $this->client = ['id' => 1, 'tenant_id' => 2, 'status' => 'inactive',
            'connection_type' => 'hotspot', 'expiry_date' => '2000-01-01 00:00:00', 'package_id' => 3];
    }
    public function exec(string $statement): int|false { return 0; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ConnectivityTestStatement($this, $query);
    }
    public function beginTransaction(): bool {
        $this->snapshot = [$this->client, $this->activations, $this->queue]; return true;
    }
    public function commit(): bool { $this->snapshot = null; return true; }
    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function rollBack(): bool {
        [$this->client, $this->activations, $this->queue] = $this->snapshot;
        $this->snapshot = null; return true;
    }
}
class ConnectivityTestStatement extends PDOStatement
{
    private mixed $row = false;
    public function __construct(private ConnectivityTestPDO $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $p = $params ?? [];
        if (str_contains($this->sql, 'GET_LOCK') || str_contains($this->sql, 'RELEASE_LOCK')) {
            $this->row = 1;
        } elseif (str_starts_with($this->sql, 'SELECT') && str_contains($this->sql, 'FROM clients')) {
            $this->row = ($p === [1, 2]) ? $this->db->client : false;
        } elseif (str_starts_with($this->sql, 'SELECT') && str_contains($this->sql, 'payment_activations')) {
            $this->row = $this->db->activations[$p[0] . ':' . $p[1]] ?? $this->db->activations[$p[0] . ':' . $p[2]] ?? false;
        } elseif (str_starts_with($this->sql, 'SELECT') && str_contains($this->sql, 'FROM packages')) {
            $this->row = $p === [9, 2] ? $this->db->package : false;
        } elseif (str_starts_with($this->sql, 'INSERT INTO payment_purchase_terms')) {
            $this->db->purchaseTerms[$p[0] . ':' . $p[2] . ':' . $p[1]] = ['package_id' => $p[3], 'validity_value' => $p[4], 'validity_unit' => $p[5]];
        } elseif (str_starts_with($this->sql, 'SELECT') && str_contains($this->sql, 'payment_purchase_terms')) {
            $this->row = $this->db->purchaseTerms[implode(':', $p)] ?? false;
        } elseif (str_starts_with($this->sql, 'UPDATE clients')) {
            $this->db->client['status'] = 'active';
            $this->db->client['expiry_date'] = $p[0];
            $this->db->client['package_id'] = $p[1];
        } elseif (str_contains($this->sql, 'INTO payment_activations')) {
            $this->db->activations[$p[0] . ':' . $p[1]] = ['client_id' => $p[2], 'expiry_date' => $p[3]];
        } elseif (str_starts_with($this->sql, 'INSERT INTO pending_provisions')) {
            if ($this->db->failQueue) throw new RuntimeException('Simulated queue failure');
            $this->db->queue[$p[0] . ':' . $p[1]] = $p;
        } else {
            throw new RuntimeException('Unexpected SQL in offline test: ' . $this->sql);
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->row;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->row; }
}
function checkConnectivity(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$db = new ConnectivityTestPDO();
$package = ['id' => 9, 'validity_value' => 30, 'validity_unit' => 'minutes'];
$before = time();
$first = activatePaidSubscription($db, 1, 2, 'checkout-1', 'receipt-1', $package);
checkConnectivity(abs(strtotime($first['expiry_date']) - ($before + 1800)) <= 1, '30-minute payment grants 30 minutes');
checkConnectivity($db->client['status'] === 'active' && $db->client['package_id'] === 9, 'Paid package is saved before provisioning');
checkConnectivity(count($db->queue) === 1, 'Activation durably queues provisioning');
$again = activatePaidSubscription($db, 1, 2, 'checkout-1', 'receipt-1', $package);
checkConnectivity($again['already_applied'] && $db->client['expiry_date'] === $first['expiry_date'], 'Repeated callback does not extend access again');
$queryThenCallback = activatePaidSubscription($db, 1, 2, 'checkout-1', 'final-receipt', $package);
checkConnectivity($queryThenCallback['already_applied'], 'Query and callback share checkout identity even when receipt changes');
$receiptOnly = activatePaidSubscription($db, 1, 2, 'final-receipt', 'final-receipt', $package);
checkConnectivity($receiptOnly['already_applied'], 'Receipt-based confirmation cannot reapply an STK payment');
$second = activatePaidSubscription($db, 1, 2, 'checkout-2', 'receipt-2', $package);
checkConnectivity(strtotime($second['expiry_date']) - strtotime($first['expiry_date']) === 1800, 'A separate payment extends remaining paid time');
$db->failQueue = true;
try { activatePaidSubscription($db, 1, 2, 'checkout-3', 'receipt-3', $package); } catch (RuntimeException $e) {}
checkConnectivity($db->client['expiry_date'] === $second['expiry_date'] && !isset($db->activations['2:checkout-3']), 'Queue failure rolls back activation for safe retry');
$db->failQueue = false;
$db->client['expiry_date'] = date('Y-m-d H:i:s', time());
checkConnectivity(!autoProvisionClient($db, 1, 2)['success'], 'Hotspot cannot be provisioned at exact expiry');
$db->client['expiry_date'] = date('Y-m-d H:i:s', time() - 1);
checkConnectivity(!autoProvisionClient($db, 1, 2)['success'], 'Expired active hotspot cannot be re-enabled by login or retry');
$db->client['status'] = 'grace';
$db->client['expiry_date'] = date('Y-m-d H:i:s', time() + 86400);
checkConnectivity(!autoProvisionClient($db, 1, 2)['success'], 'Hotspot grace status never grants access');
$db = new ConnectivityTestPDO();
activatePaidSubscription($db, 1, 2, 'missing-package', 'receipt-4', null);
checkConnectivity($db->client['status'] === 'inactive' && count($db->queue) === 1, 'Missing package is queued for repair without granting unlimited access');
$db = new ConnectivityTestPDO();
$db->client['expiry_date'] = date('Y-m-d H:i:s', time() + 86400);
$start = time();
$firstPaid = activatePaidSubscription($db, 1, 2, 'first-paid', 'receipt-5', $package);
checkConnectivity(strtotime($firstPaid['expiry_date']) <= $start + 1801, 'Unpaid registration expiry is not added to the first purchase');
$db->client['connection_type'] = 'pppoe';
$db->client['expiry_date'] = date('Y-m-d H:i:s', time() - 1);
checkConnectivity(!autoProvisionClient($db, 1, 2)['success'], 'PPPoE also cannot be re-enabled after paid expiry');
$db->client['expiry_date'] = null;
checkConnectivity(!autoProvisionClient($db, 1, 2)['success'], 'Missing expiry never grants unlimited access');
$terms = preparePaymentTerms($db, 9, 2);
recordPaymentTerms($db, 'checkout-snapshot', 1, 2, $terms);
$db->package['validity_value'] = 120;
$saved = loadPaymentTerms($db, 'checkout-snapshot', 1, 2);
checkConnectivity($saved['validity_value'] === 30 && $saved['package_id'] === 9, 'Checkout preserves purchased duration after the package is edited');
checkConnectivity(loadPaymentTerms($db, 'checkout-snapshot', 1, 3) === null, 'Purchase snapshots are tenant scoped');
echo "All payment/connectivity regression checks passed.\n";
