<?php
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/google_auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new InvalidArgumentException('Use POST to sign in.'); }
    if (!googleAuthReady()) throw new InvalidArgumentException('Google sign-in is not available yet. Please use email and password.');
    $challenge=googlePending('google_challenge');
    if (!$challenge || !hash_equals($challenge['csrf'], (string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Your sign-in session expired. Reload and try again.');
    $credential=(string)($_POST['credential'] ?? '');
    if ($credential === '' || strlen($credential)>16384) throw new InvalidArgumentException('Invalid Google credential.');
    $identity=googleVerifyCredential($credential,$challenge['nonce']);
    unset($_SESSION['google_challenge']);
    ensureGoogleIdentitySchema($pdo);
    $result=googleResolveIdentity($pdo,$identity,googleRequestTenant($pdo));
    if ($result['action'] === 'workspace') {
        echo json_encode(['success'=>false,'message'=>'Please sign in on your own workspace.','workspace_url'=>$result['url']]); exit;
    }
    session_regenerate_id(true);
    unset($_SESSION['google_signup'],$_SESSION['google_link']);
    if ($result['action']==='login') {
        $user=$result['user'];
        loginUser($user['id'],$user['username'],$user['role']);
        $_SESSION['tenant_id']=(int)$user['tenant_id'];
        $_SESSION['is_super_admin']=!empty($user['is_super_admin']);
        $st=$pdo->prepare('SELECT subdomain FROM tenants WHERE id=?'); $st->execute([$user['tenant_id']]);
        $_SESSION['tenant_subdomain']=$st->fetchColumn() ?: null;
        $redirect=!empty($user['is_super_admin']) ? 'super_admin/index.php' : 'dashboard.php';
    } elseif ($result['action']==='link') {
        $_SESSION['google_link']=$identity;
        $redirect='login.php?signin=1';
    } else {
        $_SESSION['google_signup']=$identity;
        $redirect='signup.php?google=1';
    }
    echo json_encode(['success'=>true,'redirect'=>$redirect]);
} catch (InvalidArgumentException $e) {
    if (http_response_code() === 200) http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Google sign-in: '.$e->getMessage()); http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Google sign-in could not finish. Try again or use your password.']);
}
