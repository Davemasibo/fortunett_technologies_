<?php
/**
 * MikroTik Router Configuration
 */

return [
    'default_router' => [
        'host' => '192.168.88.1',  // Change to your router IP
        'port' => 8728,
        'username' => 'admin',      // Change to your admin username
        'password' => '',           // Change to your admin password
        'use_ssl' => false,
        'timeout' => 10
    ],
    
    'pppoe' => [
        'local_address' => '10.0.0.1',
        'remote_address_pool' => '10.0.0.2-10.0.0.254',
        'default_profile' => 'default'
    ],
    
    'hotspot' => [
        'server_name' => 'hotspot1',
        'default_profile' => 'default'
    ],
    
    'debug' => false
];
