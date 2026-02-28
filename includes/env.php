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
        $name = trim($key);
        $val = trim($value, " \t\n\r\0\x0B\"'");
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $val));
            $_ENV[$name] = $val;
            $_SERVER[$name] = $val;
        }
    }
}

if (!function_exists('get_env_var')) {
    function get_env_var($key, $default = '') {
        $val = getenv($key);
        if ($val !== false) return $val;
        if (isset($_ENV[$key])) return $_ENV[$key];
        if (isset($_SERVER[$key])) return $_SERVER[$key];
        return $default;
    }
}
// If .env doesn't exist, that's OK - db_master.php will use hardcoded defaults
?>
