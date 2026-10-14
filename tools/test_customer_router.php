<?php
require_once __DIR__ . '/../includes/customer_router.php';
class CreationRouterPDO extends PDO {
    public array $routers = [1=>[11,12], 2=>[21], 3=>[]];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_contains($query, 'WHERE tenant_id=?')) throw new RuntimeException('Missing tenant scope');
        return new CreationRouterStatement($this);
    }
}
class CreationRouterStatement extends PDOStatement {
    private array $ids = [];
    public function __construct(private CreationRouterPDO $db) {}
    public function execute(?array $params = null): bool { $this->ids = $this->db->routers[$params[0]] ?? []; return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->ids; }
}
function routerCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$db = new CreationRouterPDO();
routerCheck(customerCreationRouter($db,1,'12') === 12, 'Selected router is retained');
routerCheck(customerCreationRouter($db,2,'') === 21, 'Single router is selected automatically');
routerCheck(customerCreationRouter($db,3,'') === 0, 'Accounts without routers can still save customers');
foreach (['', '21', '999', '-1', '12bad', ['12']] as $invalid) {
    $rejected = false;
    try { customerCreationRouter($db,1,$invalid); } catch (InvalidArgumentException $e) { $rejected = true; }
    routerCheck($rejected, 'Missing, foreign, deleted or malformed selection is rejected: ' . json_encode($invalid));
}
echo "Customer router checks passed. No routers contacted.\n";
