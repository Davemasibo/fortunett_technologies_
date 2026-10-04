<?php
require_once __DIR__ . '/google_auth.php';
function googleBridgeSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS google_workspace_handoffs (
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        tenant_id INT NOT NULL, state_hash CHAR(64) NOT NULL,
        kind VARCHAR(12) NOT NULL, payload TEXT NULL,
        expires_at DATETIME NOT NULL, used_at DATETIME NULL,
        INDEX google_handoff_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function googleBridgeIssue(PDO $pdo, int $tenant, string $stateHash, string $kind, ?array $payload=null): string {
    googleBridgeSchema($pdo);
    $token=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO google_workspace_handoffs(token_hash,tenant_id,state_hash,kind,payload,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))')
        ->execute([hash('sha256',$token),$tenant,$stateHash,$kind,$payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR)]);
    $pdo->exec('DELETE FROM google_workspace_handoffs WHERE expires_at < DATE_SUB(NOW(),INTERVAL 1 DAY)');
    return $token;
}
function googleBridgeRequest(PDO $pdo, string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('Google sign-in request expired. Start again from your workspace.');
    $st=$pdo->prepare("SELECT * FROM google_workspace_handoffs WHERE token_hash=? AND kind='request' AND used_at IS NULL AND expires_at>NOW()");
    $st->execute([hash('sha256',$token)]);$row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Google sign-in request expired. Start again from your workspace.');
    return $row;
}
function googleBridgeFinish(PDO $pdo, string $requestToken, array $result, array $identity): string {
    $request=googleBridgeRequest($pdo,$requestToken);
    $kind=$result['action'];
    if (!in_array($kind,['login','link'],true)) throw new InvalidArgumentException('This Google account cannot sign in to this workspace.');
    $payload=$kind==='login'?['user_id'=>(int)$result['user']['id']]:$identity;
    $ticket=googleBridgeIssue($pdo,(int)$request['tenant_id'],$request['state_hash'],$kind,$payload);
    $pdo->prepare('UPDATE google_workspace_handoffs SET used_at=NOW() WHERE token_hash=?')->execute([$request['token_hash']]);
    return str_replace('login.php?signin=1','google_return.php?ticket='.$ticket,googleTenantLoginUrl($pdo,(int)$request['tenant_id']));
}
function googleBridgeConsume(PDO $pdo, string $token, int $tenant, string $state): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token) || $state==='') throw new InvalidArgumentException('Start Google sign-in again from this workspace.');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT * FROM google_workspace_handoffs WHERE token_hash=? AND tenant_id=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');
        $st->execute([hash('sha256',$token),$tenant]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !in_array($row['kind'],['login','link'],true) || !hash_equals($row['state_hash'],hash('sha256',$state))) throw new InvalidArgumentException('Google sign-in expired or belongs to another browser. Start again.');
        $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        if ($row['kind']==='login') {
            $st=$pdo->prepare('SELECT * FROM users WHERE id=? AND tenant_id=? AND email_verified=1');$st->execute([$payload['user_id'],$tenant]);
            $payload=$st->fetch(PDO::FETCH_ASSOC);
            if (!$payload || !empty($payload['is_super_admin'])) throw new InvalidArgumentException('This account cannot sign in to this workspace.');
        }
        $pdo->prepare('UPDATE google_workspace_handoffs SET used_at=NOW() WHERE token_hash=?')->execute([$row['token_hash']]);
        $pdo->commit();return ['action'=>$row['kind'],'payload'=>$payload];
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
