<?php
require_once __DIR__ . '/../includes/dashboard_sync.php';
function checkSync(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
class DashboardRouterDouble {
    public array $calls = [];
    public bool $disabled = false, $active = true, $cookie = true, $reject = false, $ignore = false;
    public function comm($path, $args): array {
        $this->calls[] = [$path, $args];
        if (str_ends_with($path, '/set')) {
            if ($this->reject) return [['!trap' => true, 'message' => 'rejected']];
            if (!$this->ignore) $this->disabled = true;
        }
        if (str_ends_with($path, '/remove')) {
            if (str_contains($path, '/cookie/')) $this->cookie = false;
            else $this->active = false;
        }
        if (str_ends_with($path, '/print')) {
            if (str_contains($path, '/active/')) return $this->active ? [['.id' => '*2']] : [];
            if (str_contains($path, '/cookie/')) return $this->cookie ? [['.id' => '*3']] : [];
            return [['.id' => '*1', 'disabled' => $this->disabled ? 'true' : 'false']];
        }
        return [];
    }
}
foreach (['hotspot', 'pppoe'] as $service) {
    $api = new DashboardRouterDouble();
    dashboardDisableUser($api, $service, 'paid-user');
    checkSync($api->disabled && !$api->active, "$service suspension disables access and disconnects the current session");
    checkSync($api->calls[0][1] === ['?name=paid-user'], "$service commands use RouterOS wire format and filter the target user");
    if ($service === 'hotspot') checkSync(!$api->cookie, 'Hotspot suspension clears remembered login cookies');
}
foreach (['reject', 'ignore'] as $failure) {
    $api = new DashboardRouterDouble(); $api->$failure = true;
    $failed = false;
    try { dashboardDisableUser($api, 'hotspot', 'paid-user'); } catch (RuntimeException $e) { $failed = true; }
    checkSync($failed, "$failure suspension is never reported as applied");
}
class DashboardQueuePDO extends PDO {
    public array $writes = [], $reads = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new DashboardQueueStatement($this, $query); }
}
class DashboardQueueStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private DashboardQueuePDO $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $params ??= [];
        $this->db->reads[] = [$this->sql, $params];
        if (str_contains($this->sql, 'SELECT DISTINCT router_id')) $this->rows = [7, 8];
        elseif (str_contains($this->sql, 'SELECT * FROM dashboard_sync_jobs')) $this->rows = [['id'=>1,'tenant_id'=>2,'kind'=>'package','entity_id'=>3,'router_id'=>7]];
        elseif (str_contains($this->sql, 'GET_LOCK') || str_contains($this->sql, 'SELECT id FROM dashboard_sync_jobs')) $this->rows = [1];
        elseif (str_starts_with($this->sql, 'INSERT') || str_starts_with($this->sql, 'UPDATE')) $this->db->writes[] = [$this->sql, $params];
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows[0] ?? false; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->rows[0] ?? false; }
}
$db = new DashboardQueuePDO();
dashboardQueueCustomer($db, 2, 4, ['mikrotik_username'=>'old-name','connection_type'=>'hotspot']);
checkSync(count($db->writes) === 2 && $db->writes[0][1] === [2,4,7,'old-name','hotspot'] && $db->writes[1][1][2] === 8, 'Customer edits queue every assigned router and preserve old identity for removal');
$db = new DashboardQueuePDO();
dashboardProcessSync($db, 2);
checkSync(str_contains($db->reads[0][0], 'AND tenant_id=?') && $db->reads[0][1] === [2], 'Dashboard worker only selects authenticated tenant jobs');
checkSync(count($db->writes) === 1 && str_contains($db->writes[0][0], 'next_retry_at=') && !str_contains($db->writes[0][0], 'applied_at='), 'Unavailable router leaves the saved update pending for retry');
echo "All dashboard sync checks passed.\n";
