<?php
/** Versioned, bounded authenticated chunks, including a required authenticated end marker. */
function backup_encryption_key(string $password,string $salt):string
{
    if(strlen($password)<16)throw new DomainException('Use a backup password of at least 16 characters in BOOKTRACK_BACKUP_PASSWORD.');
    return hash_pbkdf2('sha256',$password,$salt,600000,32,true);
}
function backup_crypt_file(string $source,string $destination,string $password,bool $decrypt=false):void
{
    $input=fopen($source,'rb');if(!$input)throw new RuntimeException('Cannot open the backup.');
    $output=null;$complete=false;
    try{
        $header=$decrypt?fread($input,23):"BTENC1\n".random_bytes(16);
        if(strlen($header)!==23||substr($header,0,7)!=="BTENC1\n")throw new DomainException('Not a BookTrack encrypted backup.');
        $key=backup_encryption_key($password,substr($header,7));
        $output=@fopen($destination,'xb');if(!$output)throw new RuntimeException('Choose a new destination filename; existing files are never overwritten.');@chmod($destination,0600);
        $write=function(string $data)use($output):void{if(fwrite($output,$data)!==strlen($data))throw new RuntimeException('Could not write the backup.');};
        if(!$decrypt)$write($header);
        for($index=0;;$index++){
            if($decrypt){
                $length=fread($input,4);if(strlen($length)!==4)throw new DomainException('The encrypted backup is truncated.');
                $length=unpack('N',$length)[1];if($length<28||$length>1048576+28)throw new DomainException('Invalid encrypted backup chunk.');
                $block='';while(strlen($block)<$length&&!feof($input)){$part=fread($input,$length-strlen($block));if($part===false||$part==='')break;$block.=$part;}
                if(strlen($block)!==$length)throw new DomainException('The encrypted backup is truncated.');
                $end=$length===28;$aad=$header.($end?'end:':'chunk:').$index;
                $plain=openssl_decrypt(substr($block,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($block,0,12),substr($block,12,16),$aad);
                if($plain===false)throw new DomainException('Wrong backup password or damaged encrypted backup.');
                if($end){if(fread($input,1)!=='')throw new DomainException('Unexpected data after encrypted backup.');break;}$write($plain);
            }else{
                $plain=fread($input,1048576);if($plain===false)throw new RuntimeException('Could not read the backup.');$end=$plain==='';
                $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$header.($end?'end:':'chunk:').$index,16);
                if($cipher===false)throw new RuntimeException('Backup encryption failed.');$block=$nonce.$tag.$cipher;$write(pack('N',strlen($block)).$block);if($end)break;
            }
        }
        if(!fflush($output))throw new RuntimeException('Could not flush the backup.');$complete=true;
    }finally{fclose($input);if(is_resource($output)){fclose($output);if(!$complete&&!unlink($destination))error_log('BookTrack: incomplete backup retained at '.$destination);}}
}
function backup_copy_encrypted(string $source,string $directory):string
{
    if(!is_dir($directory))throw new RuntimeException('The separate backup directory is unavailable. The local encrypted backup is retained.');
    $target=rtrim($directory,'/\\').'/'.basename($source);$input=@fopen($source,'rb');
    if(!$input)throw new RuntimeException('The source backup could not be opened.');
    $output=@fopen($target,'xb');
    if(!$output){fclose($input);throw new RuntimeException('The separate copy could not start. Existing files and the local backup are retained.');}
    $ok=false;
    try{if(stream_copy_to_stream($input,$output)!==filesize($source)||!fflush($output))throw new RuntimeException('Could not write the separate backup copy.');fclose($output);$output=null;if(hash_file('sha256',$source)!==hash_file('sha256',$target))throw new RuntimeException('The separate backup checksum did not match.');$ok=true;return $target;}
    finally{fclose($input);if(is_resource($output))fclose($output);if(!$ok&&is_file($target))unlink($target);}
}
/** Preview by default. Safety, legacy, staged and unrecognized archives are excluded. */
function backup_retention_plan(int $keep,int $days):array
{
    if($keep<1||$days<1)throw new DomainException('Keep at least one backup and one day of history.');
    $files=glob(backup_directory().'/backup-*.tar')?:[];rsort($files);$plan=[];$eligible=0;
    foreach($files as $file){
        if(is_link($file)||!preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{12}\.tar$/D',basename($file)))continue;
        try{$tar=new PharData($file);$manifest=json_decode($tar['manifest.json']->getContent(),true);unset($tar);}catch(Throwable $e){continue;}
        if(($manifest['purpose']??'')!=='manual')continue;
        if(++$eligible>$keep&&filemtime($file)<time()-$days*86400)$plan[]=$file;
    }
    $files=glob(backup_directory().'/encrypted-*.btenc')?:[];rsort($files);$eligible=0;
    foreach($files as $file){
        if(is_link($file)||!preg_match('/^encrypted-\d{8}-\d{6}-[a-f0-9]{12}\.btenc$/D',basename($file)))continue;
        $handle=fopen($file,'rb');$magic=$handle?fread($handle,7):'';if($handle)fclose($handle);if($magic!=="BTENC1\n")continue;
        if(++$eligible>$keep&&filemtime($file)<time()-$days*86400)$plan[]=$file;
    }return $plan;
}
