<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/bootstrap.php';require __DIR__.'/../includes/backups.php';require __DIR__.'/../includes/backup_encryption.php';
try{
 $action=$argv[1]??'';
 if($action==='create'){echo backup_create().PHP_EOL;}
 elseif($action==='create-encrypted'){
  $plain=backup_create();$encrypted=backup_directory().'/encrypted-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.btenc';$staged=backup_directory().'/pending-encrypted-'.bin2hex(random_bytes(16)).'.part';
  try{backup_crypt_file($plain,$staged,(string)getenv('BOOKTRACK_BACKUP_PASSWORD'));if(file_exists($encrypted)||!rename($staged,$encrypted))throw new RuntimeException('Could not publish the encrypted backup.');}
  finally{if(is_file($staged))unlink($staged);if(is_file($plain)&&!unlink($plain))throw new RuntimeException('The temporary plaintext backup could not be removed.');}
  $copy=(string)config('backups.copy_directory','');
  if($copy!=='')$target=backup_copy_encrypted($encrypted,$copy);
  echo 'Encrypted backup: '.$encrypted.PHP_EOL;if($copy!=='')echo 'Verified separate copy: '.$target.PHP_EOL;
 }
 elseif($action==='decrypt'&&isset($argv[2],$argv[3])){backup_crypt_file($argv[2],$argv[3],(string)getenv('BOOKTRACK_BACKUP_PASSWORD'),true);echo 'Decrypted to: '.$argv[3].PHP_EOL;}
 elseif($action==='prune'){
  $keep=7;$days=30;foreach(array_slice($argv,2) as $option){if(preg_match('/^--keep=(\d+)$/',$option,$m))$keep=(int)$m[1];elseif(preg_match('/^--days=(\d+)$/',$option,$m))$days=(int)$m[1];elseif($option!=='--apply')throw new DomainException('Unknown retention option.');}
  with_library_write(function()use($keep,$days,$argv){$plan=backup_retention_plan($keep,$days);echo json_encode(['apply'=>in_array('--apply',$argv,true),'remove'=>$plan],JSON_PRETTY_PRINT).PHP_EOL;if(in_array('--apply',$argv,true))foreach($plan as $file)if(!unlink($file))throw new RuntimeException('Could not remove '.basename($file));});
 }
 elseif($action==='inspect'&&isset($argv[2])){$data=backup_validate($argv[2]);echo json_encode(['created_at'=>$data['manifest']['created_at'],'counts'=>$data['counts'],'attachments'=>count($data['manifest']['files']),'bytes'=>$data['bytes']],JSON_PRETTY_PRINT).PHP_EOL;}
 elseif($action==='restore'&&isset($argv[2])&&($argv[3]??'')==='--confirm-replace-library'){$safety=backup_restore($argv[2]);echo 'Restored. Safety backup: '.$safety.PHP_EOL;}
 else{fwrite(STDERR,"Usage: php tools/backup.php create | create-encrypted | decrypt file.btenc new-file.tar | inspect archive.tar | restore archive.tar --confirm-replace-library | prune --keep=7 --days=30 [--apply]\n");exit(1);}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
