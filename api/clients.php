<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once '../includes/db_master.php';
require_once '../includes/auth.php';
require_once '../includes/account_number_generator.php';
require_once '../classes/MikrotikAPI.php';

// Require authentication for all client operations
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$response = array();

// Get tenant ID from session
$tenantId = $_SESSION['tenant_id'] ?? null;

switch($method) {
    case 'GET':
        if(isset($_GET['id'])) {
            // Get single client (with tenant filter)
            $sql = "SELECT * FROM clients WHERE id = ?";
            $params = [$_GET['id']];
            
            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $response = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Get all clients (with tenant filter)
            $sql = "SELECT * FROM clients";
            $params = [];
            
            if ($tenantId) {
                $sql .= " WHERE tenant_id = ?";
                $params[] = $tenantId;
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;
    
    case 'POST':
        try {
            $data = json_decode(file_get_contents("php://input"));
            
            // Generate account number if tenant_id exists
            $accountNumber = null;
            if ($tenantId) {
                $accountGenerator = new AccountNumberGenerator($db);
                $accountNumber = $accountGenerator->generateAccountNumber($tenantId);
            }
            
            // Generate Credentials
            // Username = Account Number (lowercase)
            $portalUsername = strtolower($accountNumber);
            
            // Password = Random 6 digits if not provided
            $plainPassword = $data->password ?? str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("
                INSERT INTO clients (
                    tenant_id, account_number, full_name, name, email, phone, 
                    company, address, status, user_type, 
                    username, password, mikrotik_username, mikrotik_password
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $fullName = $data->full_name ?? $data->name ?? null;
            $name = $data->name ?? $data->full_name ?? null;
            $status = $data->status ?? 'inactive';
            $userType = $data->user_type ?? 'pppoe';
            
            if($stmt->execute([
                $tenantId,
                $accountNumber,
                $fullName,
                $name,
                $data->email ?? null,
                $data->phone ?? null,
                $data->company ?? null,
                $data->address ?? null,
                $status,
                $userType,
                $portalUsername,
                $hashedPassword,
                $portalUsername, // mikrotik_username same as portal
                $plainPassword   // mikrotik_password plain
            ])) {
                $clientId = $db->lastInsertId();
                
                // Sync to MikroTik
                try {
                    // Get active router
                    $routerStmt = $db->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status = 'active' LIMIT 1");
                    $routerStmt->execute([$tenantId]);
                    $router = $routerStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($router) {
                        $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
                        if ($api->connect()) {
                            if ($userType === 'pppoe') {
                                // Add PPPoE User
                                $api->addPPPoEUser($portalUsername, $plainPassword, 'default', 'pppoe');
                            } else {
                                // Add Hotspot User
                                $api->addHotspotUser($portalUsername, $plainPassword, 'default');
                            }
                            $api->disconnect();
                        }
                    }
                } catch (Exception $e) {
                    error_log("MikroTik Sync Failed: " . $e->getMessage());
                    // Don't fail the request, just log it
                }
                
                $response = array(
                    "success" => true,
                    "message" => "Client created successfully. Account: $accountNumber, Password: $plainPassword",
                    "id" => $clientId,
                    "account_number" => $accountNumber,
                    "username" => $portalUsername,
                    "password" => $plainPassword
                );
            } else {
                $err = $stmt->errorInfo();
                $response = array(
                    "success" => false,
                    "message" => "Failed to create client",
                    "error" => $err
                );
            }
        } catch (Exception $e) {
            $response = array(
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            );
        }
        break;
    
    case 'PUT':
        $data = json_decode(file_get_contents("php://input"));
        
        // Build update query with tenant check
        $sql = "UPDATE clients SET 
                full_name = ?, name = ?, email = ?, phone = ?, 
                company = ?, address = ?, status = ?, user_type = ?
                WHERE id = ?";
        $params = [
            $data->full_name ?? $data->name ?? null,
            $data->name ?? $data->full_name ?? null,
            $data->email ?? null,
            $data->phone ?? null,
            $data->company ?? null,
            $data->address ?? null,
            $data->status ?? 'inactive',
            $data->user_type ?? 'pppoe',
            $data->id
        ];
        
        if ($tenantId) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $stmt = $db->prepare($sql);
        
        if($stmt->execute($params)) {
            $response = array("success" => true, "message" => "Client updated successfully");
        } else {
            $err = $stmt->errorInfo();
            $response = array("success" => false, "message" => "Failed to update client", "error" => $err);
        }
        break;
    
    case 'DELETE':
        $data = json_decode(file_get_contents("php://input"));
        
        // Build delete query with tenant check
        $sql = "DELETE FROM clients WHERE id = ?";
        $params = [$data->id];
        
        if ($tenantId) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        
        $stmt = $db->prepare($sql);
        
        if($stmt->execute($params)) {
            $response = array("success" => true, "message" => "Client deleted successfully");
        } else {
            $err = $stmt->errorInfo();
            $response = array("success" => false, "message" => "Failed to delete client", "error" => $err);
        }
        break;
    
    default:
        http_response_code(405);
        $response = array("success" => false, "message" => "Method not allowed");
        break;
}

echo json_encode($response);
?>
