<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Initialize variables
$clients = [];
$message = '';
$success_message = '';
$sms_logs = [];

// add a global container for last API response so we can persist it to sms_logs
$last_sms_api_response = null;

// SMS sending function for TalkSasa (improved: accept missing api_url and try multiple auth styles)
function sendSMSTalkSasa($phone, $message, $settings) {
    global $last_sms_api_response;
    $api_url = trim($settings['api_url'] ?? '');
    $api_key = trim($settings['api_key'] ?? '');
    $sender_id = $settings['sender_id'] ?? ($settings['sender'] ?? '');

    if (empty($api_key)) {
        throw new Exception('SMS API key is not configured');
    }

    // if API URL missing, use known TalkSasa default
    if (empty($api_url)) {
        $api_url = 'https://api.talksasa.com/v1/sms/send';
    }

    // Normalize phone
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (substr($phone, 0, 1) !== '+' && substr($phone, 0, 2) !== '00') {
        if (substr($phone, 0, 1) === '0') {
            $phone = '+254' . substr($phone, 1);
        } else {
            $phone = '+254' . $phone;
        }
    }

    // Prepare two payload styles and three auth attempts:
    $payload_form = [
        'sender_id' => $sender_id,
        'phone' => $phone,
        'message' => $message,
        'api_key' => $api_key
    ];
    $payload_json = [
        'senderID' => $sender_id,
        'phone' => $phone,
        'message' => $message,
    ];

    // helper to perform request and capture response
    $doRequest = function($url, $postFields, $headers) use (&$last_sms_api_response) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);
        $last_sms_api_response = ['http_code' => $http_code, 'raw' => $response, 'curl_error' => $curl_err, 'headers' => $headers];
        return [$http_code, $response, $curl_err];
    };

    // Attempt 1: form-encoded with api_key as param (some TalkSasa endpoints accept this)
    [$code, $resp, $err] = $doRequest($api_url, http_build_query($payload_form), [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ]);
    error_log("TalkSasa attempt form => HTTP {$code} resp: " . substr($resp ?? '', 0, 800));
    if (!$err && $code >= 200 && $code < 300) return true;

    // Attempt 2: JSON body with Authorization Bearer
    [$code2, $resp2, $err2] = $doRequest($api_url, json_encode($payload_json), [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    error_log("TalkSasa attempt json+bearer => HTTP {$code2} resp: " . substr($resp2 ?? '', 0, 800));
    if (!$err2 && $code2 >= 200 && $code2 < 300) return true;

    // Attempt 3: JSON body with X-API-KEY header (some providers use this)
    [$code3, $resp3, $err3] = $doRequest($api_url, json_encode($payload_json), [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-KEY: ' . $api_key
    ]);
    error_log("TalkSasa attempt json+X-API-KEY => HTTP {$code3} resp: " . substr($resp3 ?? '', 0, 800));
    if (!$err3 && $code3 >= 200 && $code3 < 300) return true;

    // If none succeeded, prefer to return structured error with last response
    $last = $last_sms_api_response;
    $body = $last['raw'] ?? null;
    $http = $last['http_code'] ?? 0;
    $curlerr = $last['curl_error'] ?? null;
    $msg = 'SMS API request failed';
    if ($curlerr) $msg .= ": cURL error: {$curlerr}";
    if ($http) $msg .= " (HTTP {$http})";
    if ($body) $msg .= " response: " . substr($body, 0, 500);
    throw new Exception($msg);
}

// Alternative wrapper (keeps old name compat)
function sendSMSTalkSasaV2($phone, $message, $settings) {
    // just try the primary function which already has fallbacks
    return sendSMSTalkSasa($phone, $message, $settings);
}

// Main sendSMS wrapper (keeps throwing on failure)
function sendSMS($phone, $message, $settings) {
    return sendSMSTalkSasa($phone, $message, $settings);
}

try {
    // Get all clients with phone numbers (use associative arrays)
    $clients = $pdo->query("SELECT id, full_name, phone, email, mikrotik_username, created_at, status FROM clients ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Create SMS settings table if not exists (sane schema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_settings (
        id INT PRIMARY KEY NOT NULL,
        provider VARCHAR(50) DEFAULT 'talksasa',
        api_url VARCHAR(255),
        api_key VARCHAR(255),
        sender_id VARCHAR(20)
    )");

    // --- NEW: ensure updated_at column exists on sms_settings (idempotent) ---
    $colCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $colCheck->execute(['sms_settings', 'updated_at']);
    if ($colCheck->fetchColumn() == 0) {
        try {
            $pdo->exec("ALTER TABLE sms_settings ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        } catch (Exception $e) {
            error_log("Failed to add sms_settings.updated_at: " . $e->getMessage());
        }
    }

    // Ensure a row with id=1 exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_settings WHERE id = 1");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $ins = $pdo->prepare("INSERT INTO sms_settings (id, provider, api_url, sender_id) VALUES (1, 'talksasa', ?, ?)");
        $ins->execute(['https://api.talksasa.com/v1/sms/send', 'TALKSASA']);
    }
    $sms_settings = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    // after loading $sms_settings from DB ensure a default API URL for TalkSasa if none provided
    if (empty($sms_settings['api_url']) && (($sms_settings['provider'] ?? '') === 'talksasa' || empty($sms_settings['provider']))) {
        $sms_settings['api_url'] = 'https://api.talksasa.com/v1/sms/send';
    }

    // Create SMS logs table if not exists - robust schema
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        client_id INT NULL,
        recipient_phone VARCHAR(50),
        message TEXT,
        template_used VARCHAR(50),
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'sent',
        error_message TEXT NULL,
        api_response TEXT NULL,
        message_length INT DEFAULT 0,
        cost DECIMAL(10,4) DEFAULT 0
    )");

    // Ensure columns exist (idempotent check via INFORMATION_SCHEMA)
    $colCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_logs' AND COLUMN_NAME = ?");
    $ensureColumn = function($col, $ddl) use ($pdo, $colCheck) {
        $colCheck->execute([$col]);
        if ($colCheck->fetchColumn() == 0) {
            $pdo->exec($ddl);
        }
    };
    $ensureColumn('api_response', "ALTER TABLE sms_logs ADD COLUMN api_response TEXT NULL");
    $ensureColumn('message_length', "ALTER TABLE sms_logs ADD COLUMN message_length INT DEFAULT 0");
    $ensureColumn('cost', "ALTER TABLE sms_logs ADD COLUMN cost DECIMAL(10,4) DEFAULT 0");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['save_settings'])) {
            // Save SMS settings
            $provider = trim($_POST['provider'] ?? 'talksasa');
            $api_url = trim($_POST['api_url'] ?? '');
            $api_key = trim($_POST['api_key'] ?? '');
            $sender_id = trim($_POST['sender_id'] ?? '');
            
            $stmt = $pdo->prepare("UPDATE sms_settings SET provider = ?, api_url = ?, api_key = ?, sender_id = ?, updated_at = NOW() WHERE id = 1");
            $stmt->execute([$provider, $api_url, $api_key, $sender_id]);
            
            $success_message = 'SMS settings saved successfully!';
            $sms_settings = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            
        } elseif (isset($_POST['send_single_sms'])) {
            // Send single SMS
            $client_id = (int)($_POST['client_id'] ?? 0);
            $sms_message = trim($_POST['message'] ?? '');
            $template_used = $_POST['template_used'] ?? 'custom';
            
            if (!$client_id) {
                $message = 'Error: Please select a client';
            } elseif (empty($sms_message)) {
                $message = 'Error: SMS message is required';
            } else {
                $stmt = $pdo->prepare("SELECT id, full_name, phone, email, mikrotik_username FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($client && !empty($client['phone'])) {
                    // Replace placeholders
                    $final_message = str_replace(
                        ['{name}', '{email}', '{phone}', '{username}'],
                        [
                            $client['full_name'], 
                            $client['email'] ?? 'N/A', 
                            $client['phone'],
                            $client['mikrotik_username'] ?? 'N/A'
                        ],
                        $sms_message
                    );
                    
                    try {
                        // Send SMS using TalkSasa
                        $result = sendSMS($client['phone'], $final_message, $sms_settings);
                        
                        if ($result) {
                            // Calculate message length and estimated cost
                            $message_length = strlen($final_message);
                            $sms_count = ceil($message_length / 160);
                            $estimated_cost = $sms_count * 1.0; // adjust per-provider pricing if needed

                            // persist last api response if available
                            $api_resp = isset($last_sms_api_response) ? json_encode($last_sms_api_response) : null;
                            
                            // Log the SMS (include api_response)
                            $stmt = $pdo->prepare("INSERT INTO sms_logs (client_id, recipient_phone, message, template_used, sent_at, status, api_response, message_length, cost) VALUES (?, ?, ?, ?, NOW(), 'sent', ?, ?, ?)");
                            $stmt->execute([$client_id, $client['phone'], $final_message, $template_used, $api_resp, $message_length, $estimated_cost]);
                            
                            $success_message = 'SMS sent successfully to ' . htmlspecialchars($client['full_name']) . ' (' . htmlspecialchars($client['phone']) . ')';
                        }
                    } catch (Exception $e) {
                        // capture api response if present
                        $api_resp = isset($last_sms_api_response) ? json_encode($last_sms_api_response) : null;
                        // Log failed SMS
                        $stmt = $pdo->prepare("INSERT INTO sms_logs (client_id, recipient_phone, message, template_used, sent_at, status, error_message, api_response) VALUES (?, ?, ?, ?, NOW(), 'failed', ?, ?)");
                        $stmt->execute([$client_id, $client['phone'], $final_message, $template_used, $e->getMessage(), $api_resp]);
                        
                        $message = 'Error: ' . $e->getMessage();
                    }
                } else {
                    $message = 'Error: Client not found or no phone number';
                }
            }
            
        } elseif (isset($_POST['send_bulk_sms'])) {
            // Send bulk SMS
            $sms_message = trim($_POST['message'] ?? '');
            $template_used = $_POST['template_used'] ?? 'custom';
            $selected_clients = $_POST['selected_clients'] ?? [];
            
            if (empty($sms_message)) {
                $message = 'Error: SMS message is required';
            } else {
                $sent_count = 0;
                $error_count = 0;
                $total_cost = 0;
                
                // Get target clients (reload from DB to ensure latest)
                if (empty($selected_clients)) {
                    $target_clients_stmt = $pdo->query("SELECT id, full_name, phone, email, mikrotik_username FROM clients WHERE phone IS NOT NULL AND phone <> ''");
                    $target_clients = $target_clients_stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $placeholders = implode(',', array_fill(0, count($selected_clients), '?'));
                    $stmt = $pdo->prepare("SELECT id, full_name, phone, email, mikrotik_username FROM clients WHERE id IN ($placeholders)");
                    $stmt->execute($selected_clients);
                    $target_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                $total_clients = count($target_clients);
                
                foreach ($target_clients as $client) {
                    if (!empty($client['phone'])) {
                        $final_message = str_replace(
                            ['{name}', '{email}', '{phone}', '{username}'],
                            [
                                $client['full_name'], 
                                $client['email'] ?? 'N/A', 
                                $client['phone'],
                                $client['mikrotik_username'] ?? 'N/A'
                            ],
                            $sms_message
                        );
                        
                        try {
                            $result = sendSMS($client['phone'], $final_message, $sms_settings);
                            if ($result) {
                                $sent_count++;
                                $message_length = strlen($final_message);
                                $sms_count = ceil($message_length / 160);
                                $estimated_cost = $sms_count * 1.0;
                                $total_cost += $estimated_cost;
                                $api_resp = isset($last_sms_api_response) ? json_encode($last_sms_api_response) : null;
                                
                                $stmt = $pdo->prepare("INSERT INTO sms_logs (client_id, recipient_phone, message, template_used, sent_at, status, api_response, message_length, cost) VALUES (?, ?, ?, ?, NOW(), 'sent', ?, ?, ?)");
                                $stmt->execute([$client['id'], $client['phone'], $final_message, $template_used, $api_resp, $message_length, $estimated_cost]);
                            }
                        } catch (Exception $e) {
                            $error_count++;
                            $api_resp = isset($last_sms_api_response) ? json_encode($last_sms_api_response) : null;
                            $stmt = $pdo->prepare("INSERT INTO sms_logs (client_id, recipient_phone, message, template_used, sent_at, status, error_message, api_response) VALUES (?, ?, ?, ?, NOW(), 'failed', ?, ?)");
                            $stmt->execute([$client['id'], $client['phone'], $final_message, $template_used, $e->getMessage(), $api_resp]);
                        }
                    }
                }
                
                if ($error_count > 0) {
                    $message = "Bulk SMS partially sent! {$sent_count} out of {$total_clients} clients received the SMS. {$error_count} failed. Total cost: KES " . number_format($total_cost, 2);
                } else {
                    $success_message = "Bulk SMS sent successfully! {$sent_count} out of {$total_clients} clients received the SMS. Total cost: KES " . number_format($total_cost, 2);
                }
            }
        }
        
        // Test SMS functionality
        if (isset($_POST['test_sms'])) {
            $test_phone = trim($_POST['test_phone'] ?? '');
            if (!empty($test_phone)) {
                try {
                    $result = sendSMS($test_phone, 'Test SMS from your system. SMS functionality is working correctly.', $sms_settings);
                    if ($result) {
                        $api_resp = isset($last_sms_api_response) ? json_encode($last_sms_api_response) : null;
                        $stmt = $pdo->prepare("INSERT INTO sms_logs (recipient_phone, message, template_used, sent_at, status, api_response, message_length, cost) VALUES (?, ?, 'test', NOW(), 'sent', ?, ?, ?)");
                        $msglen = strlen('Test SMS from your system. SMS functionality is working correctly.');
                        $stmt->execute([$test_phone, 'Test SMS from your system. SMS functionality is working correctly.', $api_resp, $msglen, 0]);
                        $success_message = 'Test SMS sent successfully to ' . htmlspecialchars($test_phone);
                    } else {
                        $message = 'Error: Failed to send test SMS';
                    }
                } catch (Exception $e) {
                    $api_resp = isset($last_sms_api_response) ? json_encode($last_sms_api_response) : null;
                    $stmt = $pdo->prepare("INSERT INTO sms_logs (recipient_phone, message, template_used, sent_at, status, error_message, api_response) VALUES (?, ?, 'test', NOW(), 'failed', ?, ?)");
                    $stmt->execute([$test_phone, 'Test SMS from your system. SMS functionality is working correctly.', $e->getMessage(), $api_resp]);
                    $message = 'Error: ' . $e->getMessage();
                }
            } else {
                $message = 'Error: Please enter a test phone number';
            }
        }
        
        // Clear SMS logs
        if (isset($_POST['clear_logs'])) {
            $pdo->exec("DELETE FROM sms_logs");
            $success_message = 'SMS logs cleared successfully!';
        }
        
        // Resend failed SMS
        if (isset($_POST['resend_sms'])) {
            $sms_id = (int)($_POST['sms_id'] ?? 0);
            if ($sms_id) {
                $stmt = $pdo->prepare("SELECT * FROM sms_logs WHERE id = ?");
                $stmt->execute([$sms_id]);
                $sms_log = $stmt->fetch();
                
                if ($sms_log) {
                    try {
                        $result = sendSMS($sms_log['recipient_phone'], $sms_log['message'], $sms_settings);
                        
                        if ($result) {
                            // Update the log entry
                            $stmt = $pdo->prepare("UPDATE sms_logs SET status = 'sent', error_message = NULL, sent_at = NOW() WHERE id = ?");
                            $stmt->execute([$sms_id]);
                            
                            $success_message = 'SMS resent successfully!';
                        }
                    } catch (Exception $e) {
                        $message = 'Error: ' . $e->getMessage();
                    }
                } else {
                    $message = 'Error: SMS log not found';
                }
            }
        }
    }
    
    // Get clients with phone numbers
    $clients_with_phone = array_filter($clients, function($client) {
        return !empty($client['phone']);
    });
    
    $clients_without_phone = array_filter($clients, function($client) {
        return empty($client['phone']);
    });
    
    // Get SMS statistics - UPDATED QUERY (safe fetch)
    $sms_stats = $pdo->query("SELECT 
        COUNT(*) as total_sms,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_sms,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_sms,
        SUM(COALESCE(cost, 0)) as total_cost,
        COUNT(DISTINCT recipient_phone) as unique_recipients
        FROM sms_logs")->fetch(PDO::FETCH_ASSOC);
    
    // Get recent SMS logs
    $sms_logs = $pdo->query("SELECT sl.*, c.full_name AS client_name 
                              FROM sms_logs sl 
                              LEFT JOIN clients c ON sl.client_id = c.id 
                              ORDER BY sl.sent_at DESC 
                              LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
                              
} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    error_log("SMS system error: " . $e->getMessage());
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <!-- Header -->
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 style="margin: 0; color: #333;"><i class="fas fa-sms me-3"></i>SMS Center</h1>
                    <p class="text-muted mb-0">Manage client SMS communications and campaigns</p>
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#testSMSModal">
                        <i class="fas fa-vial me-2"></i>Test SMS
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#smsSettingsModal">
                        <i class="fas fa-cog me-2"></i>SMS Settings
                    </button>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Debug Information -->
        <?php if (!empty($sms_settings['api_key'])): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>SMS Configuration Status:</strong> 
            API Key: <?php echo substr($sms_settings['api_key'], 0, 10) . '...'; ?> | 
            API URL: <?php echo htmlspecialchars($sms_settings['api_url']); ?> |
            Sender ID: <?php echo htmlspecialchars($sms_settings['sender_id'] ?? 'Not set'); ?>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-primary mb-3">
                            <i class="fas fa-user fa-3x"></i>
                        </div>
                        <h5 class="card-title">Single Client</h5>
                        <p class="card-text">Send SMS to individual client</p>
                        <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#singleSMSModal">
                            <i class="fas fa-comment me-2"></i>Compose SMS
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-warning mb-3">
                            <i class="fas fa-users fa-3x"></i>
                        </div>
                        <h5 class="card-title">All Clients</h5>
                        <p class="card-text">Send bulk SMS to all clients</p>
                        <button class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#bulkSMSModal">
                            <i class="fas fa-bullhorn me-2"></i>Bulk SMS
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-info mb-3">
                            <i class="fas fa-user-friends fa-3x"></i>
                        </div>
                        <h5 class="card-title">Selected Clients</h5>
                        <p class="card-text">Send to specific clients only</p>
                        <button class="btn btn-info w-100" data-bs-toggle="modal" data-bs-target="#selectedSMSModal">
                            <i class="fas fa-check-circle me-2"></i>Select Clients
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-success mb-3">
                            <i class="fas fa-cog fa-3x"></i>
                        </div>
                        <h5 class="card-title">Settings</h5>
                        <p class="card-text">Configure SMS provider</p>
                        <button class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#smsSettingsModal">
                            <i class="fas fa-sliders me-2"></i>Configure
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- SMS Statistics -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $sms_stats['total_sms'] ?? 0; ?></h4>
                                <span>Total SMS</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-sms fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $sms_stats['sent_sms'] ?? 0; ?></h4>
                                <span>Sent SMS</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-check-circle fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $sms_stats['failed_sms'] ?? 0; ?></h4>
                                <span>Failed SMS</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-times-circle fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo $sms_stats['unique_recipients'] ?? 0; ?></h4>
                                <span>Unique Recipients</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0">KES <?php echo number_format($sms_stats['total_cost'] ?? 0, 2); ?></h4>
                                <span>Total Cost</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-money-bill fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Client Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-dark text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo count($clients); ?></h4>
                                <span>Total Clients</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo count($clients_with_phone); ?></h4>
                                <span>Clients with Phone</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-phone fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo count($clients_without_phone); ?></h4>
                                <span>Clients without Phone</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-phone-slash fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-secondary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo !empty($sms_settings['api_key']) ? 'Connected' : 'Not Set'; ?></h4>
                                <span>SMS Provider Status</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-server fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clients Phone List -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-address-book me-2"></i>
                    Client Phone Directory
                </h5>
                <span class="badge bg-light text-dark">
                    <?php echo count($clients_with_phone); ?> with phone numbers
                </span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="clientsTable">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                </th>
                                <th>Client Name</th>
                                <th>Phone Number</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <tr class="<?php echo empty($client['phone']) ? 'table-warning' : ''; ?>">
                                    <td>
                                        <?php if (!empty($client['phone'])): ?>
                                            <input type="checkbox" class="client-checkbox" name="selected_clients[]" value="<?php echo $client['id']; ?>">
                                        <?php else: ?>
                                            <i class="fas fa-exclamation-triangle text-warning" title="No phone number"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($client['phone'])): ?>
                                            <a href="tel:<?php echo htmlspecialchars($client['phone']); ?>" class="text-decoration-none">
                                                <i class="fas fa-phone me-1"></i>
                                                <?php echo htmlspecialchars($client['phone']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No phone</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($client['email'])): ?>
                                            <?php echo htmlspecialchars($client['email']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">No email</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($client['mikrotik_username'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $client['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($client['status'] ?? 'unknown'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($client['phone'])): ?>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="quickSMS(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['full_name']); ?>')"
                                                    title="Quick SMS">
                                                <i class="fas fa-comment"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bulk Actions -->
                <div class="mt-3 p-3 bg-light rounded">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <strong>Bulk Actions:</strong>
                            <span class="text-muted ms-2" id="selectedCount">0 clients selected</span>
                        </div>
                        <div class="col-md-6 text-end">
                            <button class="btn btn-info me-2" onclick="smsSelectedClients()" disabled id="smsSelectedBtn">
                                <i class="fas fa-comment me-2"></i>SMS Selected
                            </button>
                            <button class="btn btn-warning" onclick="smsAllWithPhone()">
                                <i class="fas fa-bullhorn me-2"></i>SMS All with Phone
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent SMS Activity -->
        <div class="card">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>
                    Recent SMS Activity
                </h5>
                <div>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="clear_logs" class="btn btn-warning btn-sm" onclick="return confirm('Are you sure you want to clear all SMS logs?')">
                            <i class="fas fa-trash me-1"></i>Clear Logs
                        </button>
                    </form>
                    <span class="badge bg-light text-dark ms-2">
                        Last 100 SMS
                    </span>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($sms_logs)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">No SMS history</h4>
                        <p class="text-muted">Send your first SMS to see activity here</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Recipient</th>
                                    <th>Message Preview</th>
                                    <th>Template</th>
                                    <th>Cost</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sms_logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y g:i A', strtotime($log['sent_at'])); ?></td>
                                        <td>
                                            <?php if ($log['client_name']): ?>
                                                <strong><?php echo htmlspecialchars($log['client_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($log['recipient_phone']); ?></small>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($log['recipient_phone']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars(substr($log['message'], 0, 50) . (strlen($log['message']) > 50 ? '...' : '')); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo ucfirst($log['template_used'] ?? 'custom'); ?></span>
                                        </td>
                                        <td>
                                            <small>KES <?php echo number_format($log['cost'] ?? 0, 2); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $log['status'] === 'sent' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($log['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['status'] === 'failed'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="sms_id" value="<?php echo $log['id']; ?>">
                                                    <button type="submit" name="resend_sms" class="btn btn-sm btn-outline-primary" title="Resend SMS">
                                                        <i class="fas fa-redo"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Single SMS Modal -->
<div class="modal fade" id="singleSMSModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-comment me-2"></i>Send SMS to Single Client</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="template_used" id="singleTemplate" value="custom">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Select Client *</label>
                            <select name="client_id" class="form-select" required id="singleClientSelect">
                                <option value="">-- Select Client --</option>
                                <?php foreach ($clients_with_phone as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" data-phone="<?php echo htmlspecialchars($client['phone']); ?>" data-name="<?php echo htmlspecialchars($client['full_name']); ?>">
                                        <?php echo htmlspecialchars($client['full_name'] . ' (' . $client['phone'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">SMS Template</label>
                            <select class="form-select" onchange="applySMSTemplate(this.value, 'single')" id="singleTemplateSelect">
                                <option value="">-- Choose Template --</option>
                                <option value="credentials">Login Credentials</option>
                                <option value="welcome">Welcome SMS</option>
                                <option value="expiry">Expiry Reminder</option>
                                <option value="payment">Payment Details</option>
                                <option value="report">Usage Report</option>
                                <option value="offer">Special Offer</option>
                                <option value="maintenance">Maintenance Notice</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">SMS Message *</label>
                        <textarea name="message" class="form-control" rows="6" placeholder="Type your SMS message here..." required id="singleMessage" maxlength="160"></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Available placeholders: <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{username}</code>
                            <span class="float-end"><span id="singleCharCount">0</span>/160 characters</span>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-comment me-2"></i>
                        This SMS will be sent immediately to the selected client.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" name="send_single_sms" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Send SMS
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk SMS Modal -->
<div class="modal fade" id="bulkSMSModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Send SMS to All Clients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="template_used" id="bulkTemplate" value="custom">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Bulk Template</label>
                            <select class="form-select" onchange="applySMSTemplate(this.value, 'bulk')" id="bulkTemplateSelect">
                                <option value="">-- Choose Template --</option>
                                <option value="expiry">Expiry Reminder</option>
                                <option value="payment">Payment Details</option>
                                <option value="maintenance">Maintenance Notice</option>
                                <option value="offer">Special Offer</option>
                                <option value="report">Usage Report</option>
                                <option value="announcement">General Announcement</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Recipient Count</label>
                            <input type="text" class="form-control bg-light" value="<?php echo count($clients_with_phone); ?> clients with phone numbers" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">SMS Message *</label>
                        <textarea name="message" class="form-control" rows="6" placeholder="Type your bulk SMS message here..." required id="bulkMessage" maxlength="160"></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Placeholders will be automatically replaced for each client: <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{username}</code>
                            <span class="float-end"><span id="bulkCharCount">0</span>/160 characters</span>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This will send SMS to all <?php echo count($clients_with_phone); ?> clients with valid phone numbers.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" name="send_bulk_sms" class="btn btn-warning">
                        <i class="fas fa-paper-plane me-2"></i>Send to All Clients
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Selected Clients SMS Modal -->
<div class="modal fade" id="selectedSMSModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-user-friends me-2"></i>Send SMS to Selected Clients</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="template_used" id="selectedTemplate" value="custom">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Selected Clients</label>
                        <div class="bg-light p-3 rounded" style="max-height: 200px; overflow-y: auto;" id="selectedClientsList">
                            <div class="text-muted">No clients selected. Please check clients from the table above.</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Template</label>
                            <select class="form-select" onchange="applySMSTemplate(this.value, 'selected')" id="selectedTemplateSelect">
                                <option value="">-- Choose Template --</option>
                                <option value="credentials">Login Credentials</option>
                                <option value="welcome">Welcome SMS</option>
                                <option value="expiry">Expiry Reminder</option>
                                <option value="payment">Payment Details</option>
                                <option value="report">Usage Report</option>
                                <option value="offer">Special Offer</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Recipient Count</label>
                            <input type="text" class="form-control bg-light" value="0 clients selected" readonly id="selectedCountDisplay">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">SMS Message *</label>
                        <textarea name="message" class="form-control" rows="6" placeholder="Type your SMS message here..." required id="selectedMessage" maxlength="160"></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Placeholders will be automatically replaced: <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{username}</code>
                            <span class="float-end"><span id="selectedCharCount">0</span>/160 characters</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="send_bulk_sms" class="btn btn-info">
                        <i class="fas fa-paper-plane me-2"></i>Send to Selected
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SMS Settings Modal -->
<div class="modal fade" id="smsSettingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>SMS Provider Configuration</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">SMS Provider *</label>
                            <select name="provider" class="form-select" required>
                                <option value="talksasa" <?php echo ($sms_settings['provider'] ?? '') === 'talksasa' ? 'selected' : ''; ?>>TalkSasa</option>
                                <option value="africastalking" <?php echo ($sms_settings['provider'] ?? '') === 'africastalking' ? 'selected' : ''; ?>>Africa's Talking</option>
                                <option value="twilio" <?php echo ($sms_settings['provider'] ?? '') === 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                                <option value="custom" <?php echo ($sms_settings['provider'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom API</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Sender ID</label>
                            <input type="text" name="sender_id" class="form-control" value="<?php echo htmlspecialchars($sms_settings['sender_id'] ?? ''); ?>" placeholder="TALKSASA" maxlength="11" required>
                            <div class="form-text">Max 11 characters for SMS sender ID</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">API URL *</label>
                            <select class="form-select" onchange="document.querySelector('input[name=\'api_url\']').value = this.value">
                                <option value="">-- Select API URL --</option>
                                <option value="https://api.talksasa.com/v1/sms/send">TalkSasa v1 (sms/send)</option>
                                <option value="https://api.talksasa.com/v1/sms">TalkSasa v1 (sms)</option>
                                <option value="https://api.talksasa.com/sms/send">TalkSasa Legacy</option>
                                <option value="custom">Custom URL</option>
                            </select>
                            <input type="url" name="api_url" class="form-control mt-2" value="<?php echo htmlspecialchars($sms_settings['api_url'] ?? ''); ?>" placeholder="Or enter custom API URL">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">API Key *</label>
                            <input type="text" name="api_key" class="form-control" value="<?php echo htmlspecialchars($sms_settings['api_key'] ?? ''); ?>" placeholder="Your TalkSasa API Key" required>
                            <div class="form-text">Get this from your TalkSasa dashboard</div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>TalkSasa Setup Instructions:</strong> 
                        <ol class="mb-0 mt-2">
                            <li>Get your API key from <a href="https://talksasa.com" target="_blank">TalkSasa Dashboard</a></li>
                            <li>Register your Sender ID in the TalkSasa dashboard</li>
                            <li>Use format: +2547XXXXXXXX for phone numbers</li>
                            <li>Test with the "Test SMS" button first</li>
                        </ol>
                    </div>
                    
                    <?php if (!empty($sms_settings['updated_at'])): ?>
                        <div class="alert alert-light">
                            <i class="fas fa-clock me-2"></i>
                            Last updated: <?php echo date('M j, Y g:i A', strtotime($sms_settings['updated_at'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" name="save_settings" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Test SMS Modal -->
<div class="modal fade" id="testSMSModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-vial me-2"></i>Test SMS Configuration</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Test Phone Number *</label>
                        <input type="tel" name="test_phone" class="form-control" placeholder="+2547XXXXXXXX" required>
                        <div class="form-text">Include country code (e.g., +254 for Kenya)</div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will send a test SMS to verify your SMS provider settings are working correctly.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="test_sms" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Send Test SMS
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// SMS Templates
const smsTemplates = {
    credentials: {
        message: `Hi {name}, your WiFi login: Username: {username}, Password: your phone. Connect to our network and enjoy!`
    },
    welcome: {
        message: `Welcome {name}! Your internet is activated. Username: {username}. For support call [Support Number]. Enjoy!`
    },
    expiry: {
        message: `Dear {name}, your subscription expires soon. Renew to avoid interruption. Paybill: 123456, Acc: {name}. Thank you!`
    },
    payment: {
        message: `Hello {name}, pay KES 1500 for internet. Paybill: 123456, Account: {name}. Thank you!`
    },
    report: {
        message: `Hi {name}, your monthly usage report is ready. Check your email for details. Thank you for choosing us!`
    },
    offer: {
        message: `Special offer {name}! Upgrade to premium & save 20%. Refer friends get 1 month FREE. Call [Sales Number] now!`
    },
    maintenance: {
        message: `Important: Network maintenance on [Date] [Time]. Service may be interrupted. We apologize for any inconvenience.`
    },
    announcement: {
        message: `Announcement: {name}, we have important service updates. Check your email for details. Thank you!`
    }
};

// Apply template to form
function applySMSTemplate(template, type) {
    if (template && smsTemplates[template]) {
        const modalId = type === 'single' ? 'single' : type === 'bulk' ? 'bulk' : 'selected';
        const messageField = document.getElementById(modalId + 'Message');
        const templateField = document.getElementById(modalId + 'Template');
        
        if (messageField) {
            messageField.value = smsTemplates[template].message;
            updateCharCount(messageField, modalId + 'CharCount');
        }
        if (templateField) templateField.value = template;
    }
}

// Quick SMS to single client
function quickSMS(clientId, clientName) {
    const modal = new bootstrap.Modal(document.getElementById('singleSMSModal'));
    const form = document.querySelector('#singleSMSModal form');
    form.querySelector('select[name="client_id"]').value = clientId;
    modal.show();
}

// Character count for SMS
function updateCharCount(textarea, counterId) {
    const count = textarea.value.length;
    document.getElementById(counterId).textContent = count;
    
    // Add warning class if接近 limit
    const counter = document.getElementById(counterId);
    if (count > 140) {
        counter.className = 'text-danger';
    } else if (count > 120) {
        counter.className = 'text-warning';
    } else {
        counter.className = 'text-muted';
    }
}

// Client selection management
let selectedClients = [];

function toggleSelectAll(checkbox) {
    const clientCheckboxes = document.querySelectorAll('.client-checkbox');
    clientCheckboxes.forEach(cb => {
        if (!cb.disabled) {
            cb.checked = checkbox.checked;
        }
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const selected = document.querySelectorAll('.client-checkbox:checked');
    const count = selected.length;
    document.getElementById('selectedCount').textContent = count + ' clients selected';
    document.getElementById('smsSelectedBtn').disabled = count === 0;
    
    // Update selected clients array
    selectedClients = Array.from(selected).map(cb => cb.value);
    
    // Update selected clients list for modal
    updateSelectedClientsList();
}

function updateSelectedClientsList() {
    const listContainer = document.getElementById('selectedClientsList');
    const countDisplay = document.getElementById('selectedCountDisplay');
    
    if (selectedClients.length === 0) {
        listContainer.innerHTML = '<div class="text-muted">No clients selected. Please check clients from the table above.</div>';
        countDisplay.value = '0 clients selected';
        return;
    }
    
    let html = '<div class="row">';
    selectedClients.forEach(clientId => {
        const client = <?php echo json_encode($clients); ?>.find(c => c.id == clientId);
        if (client) {
            html += `
                <div class="col-md-6 mb-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="selected_clients[]" value="${client.id}" checked>
                        <label class="form-check-label">
                            ${client.full_name} (${client.phone})
                        </label>
                    </div>
                </div>
            `;
        }
    });
    html += '</div>';
    
    listContainer.innerHTML = html;
    countDisplay.value = selectedClients.length + ' clients selected';
}

function smsSelectedClients() {
    if (selectedClients.length > 0) {
        const modal = new bootstrap.Modal(document.getElementById('selectedSMSModal'));
        modal.show();
    }
}

function smsAllWithPhone() {
    // Select all clients with phone
    const clientCheckboxes = document.querySelectorAll('.client-checkbox');
    clientCheckboxes.forEach(cb => {
        if (!cb.disabled) {
            cb.checked = true;
        }
    });
    updateSelectedCount();
    smsSelectedClients();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Update selected count when checkboxes change
    document.querySelectorAll('.client-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
    
    // Initialize selected count
    updateSelectedCount();
    
    // Character count handlers
    document.getElementById('singleMessage')?.addEventListener('input', function() {
        updateCharCount(this, 'singleCharCount');
    });
    
    document.getElementById('bulkMessage')?.addEventListener('input', function() {
        updateCharCount(this, 'bulkCharCount');
    });
    
    document.getElementById('selectedMessage')?.addEventListener('input', function() {
        updateCharCount(this, 'selectedCharCount');
    });
    
    // Initialize character counts
    const singleMessage = document.getElementById('singleMessage');
    const bulkMessage = document.getElementById('bulkMessage');
    const selectedMessage = document.getElementById('selectedMessage');
    
    if (singleMessage) updateCharCount(singleMessage, 'singleCharCount');
    if (bulkMessage) updateCharCount(bulkMessage, 'bulkCharCount');
    if (selectedMessage) updateCharCount(selectedMessage, 'selectedCharCount');
    
    // Auto-focus on client selection when single SMS modal opens
    $('#singleSMSModal').on('shown.bs.modal', function () {
        $(this).find('select[name="client_id"]').focus();
    });
});
</script>