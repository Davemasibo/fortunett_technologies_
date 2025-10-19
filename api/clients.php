<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$response = array();

switch($method) {
    case 'GET':
        if(isset($_GET['id'])) {
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $response = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->query("SELECT * FROM clients ORDER BY created_at DESC");
            $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;
    
    case 'POST':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $db->prepare("INSERT INTO clients (name, email, phone, company, address, status) VALUES (?, ?, ?, ?, ?, ?)");
        if($stmt->execute([$data->name ?? null, $data->email ?? null, $data->phone ?? null, $data->company ?? null, $data->address ?? null, $data->status ?? 'active'])) {
            $response = array("success" => true, "message" => "Client created successfully", "id" => $db->lastInsertId());
        } else {
            $err = $stmt->errorInfo();
            $response = array("success" => false, "message" => "Failed to create client", "error" => $err);
        }
        break;
    
    case 'PUT':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $db->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, company = ?, address = ?, status = ? WHERE id = ?");
        if($stmt->execute([$data->name ?? null, $data->email ?? null, $data->phone ?? null, $data->company ?? null, $data->address ?? null, $data->status ?? 'active', $data->id])) {
            $response = array("success" => true, "message" => "Client updated successfully");
        } else {
            $err = $stmt->errorInfo();
            $response = array("success" => false, "message" => "Failed to update client", "error" => $err);
        }
        break;
    
    case 'DELETE':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $db->prepare("DELETE FROM clients WHERE id = ?");
        if($stmt->execute([$data->id])) {
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
