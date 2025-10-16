<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Use the app-wide PDO instance for consistency with pages like payments.php
require_once __DIR__ . '/../includes/config.php'; // provides $pdo

// detect existing columns in clients table (runtime)
$existingColumns = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (isset($c['Field'])) $existingColumns[] = $c['Field'];
    }
} catch (Exception $e) {
    // if SHOW COLUMNS fails, leave $existingColumns empty (we'll rely on defaults)
}

// Ensure clients table has 'type' and 'expiry_date' columns (runtime safe migration)
try {
    $colStmt = $pdo->prepare("SHOW COLUMNS FROM clients LIKE 'type'");
    $colStmt->execute();
    if ($colStmt->rowCount() === 0) {
        // Add type column (hotspot or pppoe)
        $pdo->exec("ALTER TABLE clients ADD COLUMN `type` ENUM('hotspot','pppoe') DEFAULT 'hotspot' AFTER id");
    }
} catch (Exception $e) {
    // ignore migration errors
}
try {
    $colStmt2 = $pdo->prepare("SHOW COLUMNS FROM clients LIKE 'expiry_date'");
    $colStmt2->execute();
    if ($colStmt2->rowCount() === 0) {
        // Add expiry_date column (nullable)
        $pdo->exec("ALTER TABLE clients ADD COLUMN `expiry_date` DATETIME NULL AFTER address");
    }
} catch (Exception $e) {
    // ignore migration errors
}

// Ensure clients table has 'package_id' and 'package_name' columns (runtime safe migration)
try {
    $colPkg = $pdo->prepare("SHOW COLUMNS FROM clients LIKE 'package_id'");
    $colPkg->execute();
    if ($colPkg->rowCount() === 0) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN `package_id` INT NULL AFTER address");
    }
} catch (Exception $e) {
    // ignore
}

// Ensure clients table has 'password' column (runtime safe migration)
try {
    $colPwd = $pdo->prepare("SHOW COLUMNS FROM clients LIKE 'password'");
    $colPwd->execute();
    if ($colPwd->rowCount() === 0) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN `password` VARCHAR(191) NULL AFTER name");
    }
} catch (Exception $e) {
    // ignore
}
try {
    $colPkgName = $pdo->prepare("SHOW COLUMNS FROM clients LIKE 'package_name'");
    $colPkgName->execute();
    if ($colPkgName->rowCount() === 0) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN `package_name` VARCHAR(191) NULL AFTER package_id");
    }
} catch (Exception $e) {
    // ignore
}

$method = $_SERVER['REQUEST_METHOD'];
$response = array();

// Helper: choose column based on availability
$resolveClientNameInsert = function($nameValue) use (&$existingColumns) {
    if (in_array('name', $existingColumns)) {
        return ['name' => $nameValue];
    }
    if (in_array('full_name', $existingColumns)) {
        return ['full_name' => $nameValue];
    }
    // If we don't know columns, default to name
    return ['name' => $nameValue];
};

// Helper: normalize row(s) to always include 'name' field
$normalizeRow = function(array $row) {
    if ((!isset($row['name']) || $row['name'] === null || $row['name'] === '') && isset($row['full_name'])) {
        $row['name'] = $row['full_name'];
    }
    return $row;
};

switch($method) {
    case 'GET':
        if(isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['expiry_date']) && $row['expiry_date'] !== null) {
                // normalize datetime to ISO 8601
                $row['expiry_date'] = date('c', strtotime($row['expiry_date']));
            }
            if ($row) { $row = $normalizeRow($row); }
            $response = $row;
        } else {
            // support filtering by type: ?type=hotspot|pppoe or omitted for all
            if (isset($_GET['type']) && in_array($_GET['type'], ['hotspot','pppoe'])) {
                $type = $_GET['type'];
                $stmt = $pdo->prepare("SELECT * FROM clients WHERE `type` = ? ORDER BY created_at DESC");
                $stmt->execute([$type]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            // normalize expiry_date for each row if present
            foreach ($rows as &$r) {
                if (isset($r['expiry_date']) && $r['expiry_date'] !== null) {
                    $r['expiry_date'] = date('c', strtotime($r['expiry_date']));
                }
                $r = $normalizeRow($r);
            }
            $response = $rows;
        }
        break;
    
    case 'POST':
        $data = json_decode(file_get_contents("php://input"));
        // Basic validation: require name and password
        if (empty($data->name)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Name is required"]);
            exit;
        }
        if (!isset($data->password) || $data->password === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Password is required"]);
            exit;
        }

        // Normalize values
        $type = isset($data->type) && in_array($data->type, ['hotspot','pppoe']) ? $data->type : 'hotspot';
        $expiry = isset($data->expiry_date) && !empty($data->expiry_date) ? date('Y-m-d H:i:s', strtotime($data->expiry_date)) : null;
        $package_id_input = isset($data->package_id) && is_numeric($data->package_id) ? (int)$data->package_id : null;
        $package_name_resolved = null;

        // Try to resolve package name if packages table exists and package_id provided
        if ($package_id_input) {
            try {
                $pstmt = $pdo->prepare("SELECT name FROM packages WHERE id = ? LIMIT 1");
                $pstmt->execute([$package_id_input]);
                $p = $pstmt->fetch(PDO::FETCH_ASSOC);
                if ($p) $package_name_resolved = $p['name'];
            } catch (Exception $e) {
                // ignore if packages table missing
            }
        } elseif (!empty($data->package_name)) {
            $package_name_resolved = $data->package_name;
        }

        // Build insert dynamically based on existing columns
        $insertCols = [];
        $placeholders = [];
        $values = [];

        $maybeAdd = function($col, $val) use (&$existingColumns, &$insertCols, &$placeholders, &$values) {
            if (empty($existingColumns) || in_array($col, $existingColumns)) {
                $insertCols[] = $col;
                $placeholders[] = '?';
                $values[] = $val;
            }
        };

        // Prefer 'name' column, fallback to 'full_name' if needed
        $nameMap = $resolveClientNameInsert($data->name ?? null);
        foreach ($nameMap as $col => $val) { $maybeAdd($col, $val); }
        $maybeAdd('email', $data->email ?? null);
        $maybeAdd('phone', $data->phone ?? null);
        // Optional password field if such a column exists
        // password required on POST; already validated above
        $maybeAdd('password', $data->password);
        $maybeAdd('company', $data->company ?? null);
        $maybeAdd('address', $data->address ?? null);
        $maybeAdd('status', $data->status ?? 'active');
        $maybeAdd('type', $type);
        $maybeAdd('expiry_date', $expiry);
        // package_id/package_name only if columns exist
        $maybeAdd('package_id', $package_id_input);
        $maybeAdd('package_name', $package_name_resolved);

        if (empty($insertCols)) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "No writable columns detected on clients table"]);
            exit;
        }

        $sql = "INSERT INTO clients (" . implode(", ", $insertCols) . ") VALUES (" . implode(", ", $placeholders) . ")";
        try {
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($values)) {
                $newId = $pdo->lastInsertId();
                // Return normalized object
                $created = [ 'id' => (int)$newId, 'type' => $type, 'status' => ($data->status ?? 'active'), 'phone' => ($data->phone ?? null), 'address' => ($data->address ?? null), 'expiry_date' => $expiry, 'package_id' => $package_id_input, 'package_name' => $package_name_resolved ];
                $created['name'] = $data->name ?? null;
                $response = array("success" => true, "message" => "Client created successfully", "id" => $newId, "data" => $created);
            } else {
                $err = $stmt->errorInfo();
                $response = array("success" => false, "message" => "Failed to create client", "error" => $err);
            }
        } catch (Exception $e) {
            http_response_code(500);
            $response = array("success" => false, "message" => "DB error during create", "error" => $e->getMessage());
        }
        break;
    
    case 'PUT':
        $data = json_decode(file_get_contents("php://input"));
        if (empty($data->id)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Client id is required"]);
            exit;
        }

        // Normalize values
        $type = isset($data->type) && in_array($data->type, ['hotspot','pppoe']) ? $data->type : ($data->type ?? null);
        $expiry = isset($data->expiry_date) && !empty($data->expiry_date) ? date('Y-m-d H:i:s', strtotime($data->expiry_date)) : null;
        $package_id_input = isset($data->package_id) && is_numeric($data->package_id) ? (int)$data->package_id : null;
        $package_name_resolved = null;

        if ($package_id_input) {
            try {
                $pstmt = $pdo->prepare("SELECT name FROM packages WHERE id = ? LIMIT 1");
                $pstmt->execute([$package_id_input]);
                $p = $pstmt->fetch(PDO::FETCH_ASSOC);
                if ($p) $package_name_resolved = $p['name'];
            } catch (Exception $e) { /* ignore */ }
        } elseif (!empty($data->package_name)) {
            $package_name_resolved = $data->package_name;
        }

        // Build update SET clauses dynamically
        $setParts = [];
        $values = [];

        $maybeSet = function($col, $val) use (&$existingColumns, &$setParts, &$values) {
            if (empty($existingColumns) || in_array($col, $existingColumns)) {
                $setParts[] = "`$col` = ?";
                $values[] = $val;
            }
        };

        if (isset($data->name)) {
            if (in_array('name', $existingColumns)) { $maybeSet('name', $data->name); }
            elseif (in_array('full_name', $existingColumns)) { $maybeSet('full_name', $data->name); }
        }
        $maybeSet('email', $data->email ?? null);
        $maybeSet('phone', $data->phone ?? null);
        if (isset($data->password) && $data->password !== '') {
            $maybeSet('password', $data->password);
        }
        $maybeSet('company', $data->company ?? null);
        $maybeSet('address', $data->address ?? null);
        $maybeSet('status', $data->status ?? 'active');
        $maybeSet('type', $type);
        $maybeSet('expiry_date', $expiry);
        $maybeSet('package_id', $package_id_input);
        $maybeSet('package_name', $package_name_resolved);

        if (empty($setParts)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "No updatable columns detected"]);
            exit;
        }

        $values[] = $data->id;
        $sql = "UPDATE clients SET " . implode(", ", $setParts) . " WHERE id = ?";
        try {
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($values)) {
                $response = array("success" => true, "message" => "Client updated successfully");
            } else {
                $err = $stmt->errorInfo();
                $response = array("success" => false, "message" => "Failed to update client", "error" => $err);
            }
        } catch (Exception $e) {
            http_response_code(500);
            $response = array("success" => false, "message" => "DB error during update", "error" => $e->getMessage());
        }
        break;
    
    case 'DELETE':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
        if($stmt->execute([$data->id])) {
            $response = array("success" => true, "message" => "Client deleted successfully");
        } else {
            $response = array("success" => false, "message" => "Failed to delete client");
        }
        break;
}

echo json_encode($response);
?>
