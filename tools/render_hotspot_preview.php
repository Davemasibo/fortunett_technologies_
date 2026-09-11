<?php
/** Offline preview using the production renderer and approved tariff fixtures. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../hotspot/render_login.php';
class PortalPreviewPDO extends PDO {
    public function __construct() {}
    public function exec(string $statement): int|false { return 0; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new PortalPreviewStatement($query); }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false { return new PortalPreviewStatement($query); }
}
class PortalPreviewStatement extends PDOStatement {
    public function __construct(private string $sql) {}
    public function execute(?array $params=null): bool { return true; }
    public function fetchColumn(int $column=0): mixed { return 'portal.test'; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if (str_contains($this->sql,'tenant_settings')) {
            $optional=getenv('PORTAL_BUY_ONLY')==='1'?'0':'1';
            return [HS_SETTING_PREFIX.'show_login'=>$optional,HS_SETTING_PREFIX.'show_paid'=>$optional,
                HS_SETTING_PREFIX.'show_voucher'=>$optional,HS_SETTING_PREFIX.'show_manual'=>'0',HS_SETTING_PREFIX.'paybill'=>'000000'];
        }
        if (str_contains($this->sql,'FROM packages')) {
            $tariffs=json_decode(file_get_contents(__DIR__.'/../config/ghettohlink_hotspot_tariffs.json'),true);
            $packages=[];
            foreach ($tariffs['prices'] as $price=>$rule) $packages[]=[
                'id'=>$rule['package_id'],'name'=>$rule['validity_value'].' '.$rule['validity_unit'],
                'price'=>$price,'download_speed'=>10,'data_limit'=>0,'device_limit'=>1,
                'validity_value'=>$rule['validity_value'],'validity_unit'=>$rule['validity_unit']];
            return $packages;
        }
        return [];
    }
}
echo renderHotspotLoginPage(new PortalPreviewPDO(),['id'=>9,'company_name'=>'Ghettoh link network solutions','subdomain'=>'']);
