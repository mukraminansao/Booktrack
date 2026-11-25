<?php
/** Authenticated encryption. The installation key never enters a database backup. */
function installation_key():string
{
    $environment=getenv('BOOKTRACK_ENCRYPTION_KEY');
    if($environment!==false&&$environment!==''){$key=base64_decode($environment,true);if($key===false||strlen($key)!==32)throw new RuntimeException('The installation encryption key is invalid.');return $key;}
    $path=(string)config('security.key_file',APP_ROOT.'/storage/keys/'.operation_identity().'.key');
    if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true))throw new RuntimeException('The encryption key directory is unavailable.');
    $file=fopen($path,'c+b');if(!$file||!flock($file,LOCK_EX))throw new RuntimeException('The encryption key is unavailable.');
    try{$key=stream_get_contents($file);if($key===''){$key=random_bytes(32);if(fwrite($file,$key)!==32||!fflush($file))throw new RuntimeException('Could not save the installation key.');@chmod($path,0600);}if(strlen($key)!==32)throw new RuntimeException('The installation key is damaged. Restore its separate key copy.');return $key;}
    finally{flock($file,LOCK_UN);fclose($file);}
}
function installation_key_id():string{return substr(hash('sha256',installation_key()),0,32);}
function secret_seal(string $plain,string $purpose):string
{
    $nonce=random_bytes(12);$tag='';$encrypted=openssl_encrypt($plain,'aes-256-gcm',installation_key(),OPENSSL_RAW_DATA,$nonce,$tag,$purpose,16);
    if($encrypted===false)throw new RuntimeException('Encryption failed.');return 'enc1:'.base64_encode($nonce.$tag.$encrypted);
}
function secret_open(string $sealed,string $purpose):string
{
    if(!str_starts_with($sealed,'enc1:'))return $sealed; // legacy secrets migrate on access
    $raw=base64_decode(substr($sealed,5),true);if($raw===false||strlen($raw)<28)throw new RuntimeException('Encrypted secret is damaged.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',installation_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$purpose);
    if($plain===false)throw new RuntimeException('The secret could not be decrypted. Restore the matching installation key.');return $plain;
}
