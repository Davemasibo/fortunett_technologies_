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
        if($stmt->execute([$data->name, $data->email, $data->phone, $data->company, $data->address, $data->status ?? 'active'])) {
            $response = array("success" => true, "message" => "Client created successfully", "id" => $db->lastInsertId());
        } else {
            $response = array("success" => false, "message" => "Failed to create client");
        }
        break;
    
    case 'PUT':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $db->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, company = ?, address = ?, status = ? WHERE id = ?");
        if($stmt->execute([$data->name, $data->email, $data->phone, $data->company, $data->address, $data->status, $data->id])) {
            $response = array("success" => true, "message" => "Client updated successfully");
        } else {
            $response = array("success" => false, "message" => "Failed to update client");
        }
        break;
    
    case 'DELETE':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $db->prepare("DELETE FROM clients WHERE id = ?");
        if($stmt->execute([$data->id])) {
            $response = array("success" => true, "message" => "Client deleted successfully");
        } else {
            $response = array("success" => false, "message" => "Failed to delete client");
        }
        break;
}

echo json_encode($response);
?>
