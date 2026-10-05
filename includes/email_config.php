<?php
require_once __DIR__.'/env.php';
function fortunettPlatformEmailConfig(PDO $pdo): array {
    $config=[];
    if (get_env_var('MAIL_HOST') && get_env_var('MAIL_USERNAME')) {
        $config=['smtp_host'=>get_env_var('MAIL_HOST'),'smtp_port'=>get_env_var('MAIL_PORT',587),'smtp_username'=>get_env_var('MAIL_USERNAME'),'smtp_password'=>get_env_var('MAIL_PASSWORD'),'from_email'=>get_env_var('MAIL_FROM_ADDRESS',get_env_var('MAIL_FROM_EMAIL',get_env_var('MAIL_USERNAME'))),'from_name'=>get_env_var('MAIL_FROM_NAME','FortuNett Technologies'),'reply_to'=>get_env_var('MAIL_REPLY_TO')];
    } else {
        foreach (['platform_email_config','email_settings'] as $table) {
            try {
                $row=$pdo->query('SELECT * FROM '.$table.' WHERE id=1'.($table==='platform_email_config' ? ' AND is_active=1' : '').' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                if (!empty($row['smtp_host'])) {$config=$row; break;}
            } catch (PDOException $e) {if ($e->getCode()!=='42S02') throw $e;}
        }
    }
    if (empty($config['from_email'])) $config['from_email']=$config['smtp_username'] ?? '';
    if (empty($config['from_name'])) $config['from_name']='FortuNett Technologies';
    return $config;
}
