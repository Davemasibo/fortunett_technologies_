<?php
/**
 * Check Subdomain Availability
 * Used during signup to validate subdomain in real-time
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../includes/tenant.php';

// Allow CORS for testing
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $subdomain = $_POST['subdomain'] ?? '';
    
    if (empty($subdomain)) {
        echo json_encode([
            'success' => false,
            'message' => 'Subdomain is required'
        ]);
        exit;
    }
    
    // Sanitize subdomain
    $subdomain = TenantManager::sanitizeSubdomain($subdomain);
    
    if (strlen($subdomain) < 3) {
        echo json_encode([
            'success' => false,
            'message' => 'Subdomain must be at least 3 characters long',
            'suggested' => $subdomain
        ]);
        exit;
    }
    
    // Check availability
    $tenantManager = TenantManager::getInstance($pdo);
    $available = $tenantManager->isSubdomainAvailable($subdomain);
    
    if ($available) {
        echo json_encode([
            'success' => true,
            'available' => true,
            'subdomain' => $subdomain,
            'full_url' => "https://{$subdomain}.fortunetttech.site",
            'message' => 'Subdomain is available!'
        ]);
    } else {
        // Suggest alternatives
        $suggestions = [];
        for ($i = 1; $i <= 3; $i++) {
            $suggested = $subdomain . $i;
            if ($tenantManager->isSubdomainAvailable($suggested)) {
                $suggestions[] = $suggested;
            }
        }
        
        echo json_encode([
            'success' => true,
            'available' => false,
            'subdomain' => $subdomain,
            'message' => 'Subdomain is already taken',
            'suggestions' => $suggestions
        ]);
    }
    
} catch (Exception $e) {
    error_log("Subdomain check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while checking subdomain availability'
    ]);
}
