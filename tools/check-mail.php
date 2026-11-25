<?php
/** CLI only; no delivery happens unless the operator explicitly supplies --send and --to. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('APP_ROOT',dirname(__DIR__));require APP_ROOT.'/includes/load_config.php';$GLOBALS['__config']=load_config(APP_ROOT);
function config(string $key,$default=null){$value=$GLOBALS['__config'];foreach(explode('.',$key) as $part){if(!is_array($value)||!array_key_exists($part,$value))return $default;$value=$value[$part];}return $value;}
$smtp=(array)config('mail.smtp',[]);$ready=config('mail.driver')==='smtp'&&!empty($smtp['host'])&&!empty($smtp['username'])&&!empty($smtp['password'])&&in_array($smtp['encryption']??'',['tls','ssl'],true)&&($smtp['verify_peer']??true);
$recipient='';foreach(array_slice($argv,1) as $option){if(str_starts_with($option,'--to='))$recipient=substr($option,5);elseif($option!=='--send'){fwrite(STDERR,"Usage: php tools/check-mail.php [--send --to=your-mailbox@example.com]\n");exit(1);}}
if(!in_array('--send',$argv,true)){echo json_encode(['configured'=>$ready,'sent'=>false,'next'=>'Configure verified TLS SMTP, then explicitly run --send --to=your-own-mailbox and confirm receipt.'],JSON_PRETTY_PRINT).PHP_EOL;exit($ready?0:2);}
if(!$ready||!filter_var($recipient,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Verified TLS SMTP configuration and a valid --to recipient are required. Nothing was sent.\n");exit(2);}
require APP_ROOT.'/includes/mailer.php';$tag=bin2hex(random_bytes(5));
$sent=send_mail($recipient,'BookTrack operator','BookTrack delivery check '.$tag,"This message was explicitly requested by the BookTrack operator.\nDelivery reference: $tag\nConfirm this reference in your inbox to complete mail acceptance.\n");
echo json_encode(['accepted_by_smtp'=>$sent,'reference'=>$tag,'receipt_confirmed'=>false,'next'=>'SMTP acceptance does not prove inbox delivery. Confirm receipt before marking email operational.'],JSON_PRETTY_PRINT).PHP_EOL;exit($sent?0:2);
