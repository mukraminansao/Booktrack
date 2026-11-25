<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/load_config.php';$settings=load_config(dirname(__DIR__));
if(($settings['db']['driver']??'')!=='sqlite'||empty($settings['demo']['enabled'])){fwrite(STDERR,"Demo preparation requires the isolated SQLite demo configuration.\n");exit(1);}
require __DIR__.'/../includes/bootstrap.php';installation_key();
echo "Demo schema, operation locks and private encryption key are ready.\n";
