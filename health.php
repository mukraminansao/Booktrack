<?php
/** Minimal, read-only HTTP liveness/readiness signal. Details remain private. */
require __DIR__.'/includes/load_config.php';require __DIR__.'/includes/health.php';
ini_set('display_errors','0');header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
$settings=load_config(__DIR__);
if(!empty($settings['security']['require_https'])&&(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')){
    $base=parse_url((string)$settings['app_url']);
    if(($base['scheme']??'')==='https'&&!empty($base['host'])){header('Location: https://'.$base['host'].(isset($base['port'])?':'.$base['port']:'').'/'.ltrim($_SERVER['REQUEST_URI']??'/','/'),true,308);exit;}
    http_response_code(503);if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo '{"status":"unavailable"}';exit;
}
if(!empty($settings['security']['require_https']))header('Strict-Transport-Security: max-age=31536000');
$ok=booktrack_health($settings);http_response_code($ok?200:503);if(!$ok)header('Retry-After: 10');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo json_encode(['status'=>$ok?'ok':'unavailable']);
