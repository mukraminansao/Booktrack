<?php
/**
 * Settings: config.php holds the defaults (it's in git), and config.local.php,
 * if it exists, overrides any of them for this server (it's not in git, so
 * passwords and SMTP details never end up in the public repository).
 * Copy config.local.example.php to config.local.php to start one.
 */
function load_config(string $root): array
{
    $config = require $root . '/config.php';
    $local = $root . '/config.local.php';
    if (is_file($local)) {
        $override = require $local;
        if (is_array($override)) $config = array_replace_recursive($config, $override);
    }
    $variables=['BOOKTRACK_APP_URL'=>'app_url','BOOKTRACK_DB_HOST'=>'db.host','BOOKTRACK_DB_NAME'=>'db.name','BOOKTRACK_DB_USER'=>'db.user','BOOKTRACK_DB_PASSWORD'=>'db.pass','BOOKTRACK_KEY_FILE'=>'security.key_file','BOOKTRACK_BACKUP_DIRECTORY'=>'backups.directory','BOOKTRACK_BACKUP_COPY_DIRECTORY'=>'backups.copy_directory','BOOKTRACK_MAIL_DRIVER'=>'mail.driver'];
    foreach($variables as $environment=>$path){$value=getenv($environment);if($value===false)continue;$target=&$config;foreach(explode('.',$path) as $part)$target=&$target[$part];$target=$value;unset($target);}
    if(($sqlite=getenv('BOOKTRACK_SQLITE'))!==false&&$sqlite!==''){$config['db']['driver']='sqlite';$config['db']['sqlite_path']=$sqlite;}
    if(getenv('BOOKTRACK_ENV')==='production'||($config['environment']??'')==='production'){
        $config=array_replace_recursive($config,['environment'=>'production','debug'=>false,'auth'=>['show_demo_logins'=>false,'require_2fa_for_librarians'=>true],'demo'=>['enabled'=>false,'reset_daily'=>false],'security'=>['require_https'=>true]]);
    }
    return $config;
}
