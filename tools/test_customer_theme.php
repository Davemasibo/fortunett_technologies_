<?php
/** Tenant isolation, branding fallback, safe palettes and portal parity. */
require_once __DIR__.'/../customer/includes/theme.php';
class ThemeTestPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new ThemeTestStatement(); }
}
class ThemeTestStatement extends PDOStatement {
    private int $tenantId;
    public function execute(?array $params=null): bool { $this->tenantId=(int)$params[0]; return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        return match($this->tenantId) {
            1=>['hs_accent'=>'#ffd900','hs_bg_color'=>'#ffffff','hs_bg_style'=>'solid','hs_card_mode'=>'light'],
            2=>['hs_accent'=>'#00856a','hs_bg_color'=>'#101010','hs_bg_style'=>'solid','hs_card_mode'=>'dark'],
            3=>['brand_color'=>'#06c'],
            4=>['hs_accent'=>'</style><script>alert(1)</script>','hs_bg_color'=>'url(evil)','hs_radius'=>'999'],
            default=>[],
        };
    }
}
$pdo=new ThemeTestPDO();$checks=0;
function themeCheck(bool $ok,string $label): void { global $checks; if(!$ok) throw new RuntimeException($label); $checks++; }
foreach([1,2,3,4] as $tenantId) {
    $saved=hotspotThemeLoad($pdo,$tenantId);$customer=customerThemeData($pdo,$tenantId);
    themeCheck($customer['vars']===hotspotThemePalette($saved)['vars'],'Customer and captive palettes match');
    themeCheck(strpos($customer['vars'],'</style>')===false,'Unsafe CSS rejected');
    themeCheck(hsContrast(hsBestInk($saved['accent']),$saved['accent'])>=4.5,'Readable button text');
}
themeCheck(customerThemeData($pdo,1)['accent']==='#ffd900','Tenant one isolated');
themeCheck(customerThemeData($pdo,2)['accent']==='#00856a','Tenant two isolated');
themeCheck(customerThemeData($pdo,3)['accent']==='#0066cc','General brand fallback');
themeCheck(customerThemeData($pdo,1)['is_light']===true && customerThemeData($pdo,2)['is_light']===false,'Light and dark modes honored');
echo "PASS: $checks tenant theme checks\n";
