<?php
/** Copy to config.local.php on the production host only. Replace every placeholder. */
return [
    'environment'=>'production',
    'app_url'=>'https://booktrack.example.com',
    'db'=>['host'=>'127.0.0.1','name'=>'booktrack_library','user'=>'booktrack_app','pass'=>'REPLACE-WITH-A-STRONG-DATABASE-PASSWORD'],
    // Move the SAME existing installation key here before switching the setting.
    // BOOKTRACK_ENCRYPTION_KEY (base64, 32 bytes) is an alternative secret-store setting.
    'security'=>['key_file'=>'C:/BookTrack-private/installation.key'],
    // Use a mounted off-server destination. No automatic upload or deletion is scheduled.
    'backups'=>['directory'=>'C:/BookTrack-private/backups','copy_directory'=>'Z:/BookTrack-backups'],
    'mail'=>['driver'=>'smtp','from'=>'library@example.com','from_name'=>'BookTrack Library','smtp'=>[
        'host'=>'smtp.example.com','port'=>587,'encryption'=>'tls','verify_peer'=>true,
        'username'=>'REPLACE-WITH-SMTP-USERNAME','password'=>'REPLACE-WITH-SMTP-PASSWORD',
    ]],
];
