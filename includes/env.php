<?php
// includes/env.php
// Loads environment variables from .env file if it exists
// LOCALHOST: .env doesn't exist - script continues without it
// VPS: .env exists - loads database credentials and secrets

$envPath = dirname(__DIR__) . '/.env';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
// If .env doesn't exist, that's OK - db_master.php will use hardcoded defaults
?>
