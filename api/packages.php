<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once __DIR__ . '/../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$response = array();

try {
    switch($method) {
        case 'GET':
            if(isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $response = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->query("SELECT * FROM packages ORDER BY created_at DESC");
                $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            break;
        
        case 'POST':
            $data = json_decode(file_get_contents("php://input"));
            // Ensure required columns exist
            try { $pdo->query("SELECT type FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN type ENUM('hotspot','pppoe') DEFAULT 'hotspot'"); } catch (Exception $ignored) {} }
            try { $pdo->query("SELECT duration FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN duration VARCHAR(50) DEFAULT '30 days'"); } catch (Exception $ignored) {} }
            try { $pdo->query("SELECT features FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN features TEXT"); } catch (Exception $ignored) {} }
            try { $pdo->query("SELECT download_speed FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN download_speed INT DEFAULT 0"); } catch (Exception $ignored) {} }
            try { $pdo->query("SELECT upload_speed FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN upload_speed INT DEFAULT 0"); } catch (Exception $ignored) {} }
            try { $pdo->query("SELECT data_limit FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN data_limit BIGINT DEFAULT 0"); } catch (Exception $ignored) {} }
            try { $pdo->query("SELECT allowed_clients FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN allowed_clients INT DEFAULT 1"); } catch (Exception $ignored) {} }
            $stmt = $pdo->prepare("INSERT INTO packages (name, type, price, duration, features, download_speed, upload_speed, data_limit, allowed_clients) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if($stmt->execute([
                $data->name,
                $data->type ?? 'hotspot',
                $data->price,
                $data->duration ?? '30 days',
                $data->features ?? '',
                $data->download_speed ?? 0,
                $data->upload_speed ?? 0,
                $data->data_limit ?? 0,
                $data->allowed_clients ?? 1
            ])) {
                $response = array("success" => true, "message" => "Package created successfully", "id" => $pdo->lastInsertId());
            } else {
                $response = array("success" => false, "message" => "Failed to create package");
            }
            break;
        
        case 'PUT':
            $data = json_decode(file_get_contents("php://input"));
            $stmt = $pdo->prepare("UPDATE packages SET name = ?, type = ?, price = ?, duration = ?, features = ?, download_speed = ?, upload_speed = ?, data_limit = ?, allowed_clients = ? WHERE id = ?");
            if($stmt->execute([
                $data->name,
                $data->type ?? 'hotspot',
                $data->price,
                $data->duration ?? '30 days',
                $data->features ?? '',
                $data->download_speed ?? 0,
                $data->upload_speed ?? 0,
                $data->data_limit ?? 0,
                $data->allowed_clients ?? 1,
                $data->id
            ])) {
                $response = array("success" => true, "message" => "Package updated successfully");
            } else {
                $response = array("success" => false, "message" => "Failed to update package");
            }
            break;
        
        case 'DELETE':
            $data = json_decode(file_get_contents("php://input"));
            $stmt = $pdo->prepare("DELETE FROM packages WHERE id = ?");
            if($stmt->execute([$data->id])) {
                $response = array("success" => true, "message" => "Package deleted successfully");
            } else {
                $response = array("success" => false, "message" => "Failed to delete package");
            }
            break;
    }
} catch(PDOException $e) {
    $response = array("success" => false, "message" => "Database error: " . $e->getMessage());
}

echo json_encode($response);
?>
