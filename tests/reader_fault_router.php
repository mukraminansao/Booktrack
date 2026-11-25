<?php
// Used only by an isolated PHP development server for browser failure checks.
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
$runtime=getenv('BOOKTRACK_FAULT_RUNTIME');
if($runtime && parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/api/reading.php' && $_SERVER['REQUEST_METHOD']==='GET' && is_file($runtime.'/fail-reading-get')){
    http_response_code(503);header('Content-Type: application/json');header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'error'=>'The test progress service is unavailable.']);return true;
}
return false;
