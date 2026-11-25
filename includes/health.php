<?php
/** Monitoring must not start sessions, run migrations, reset demos or send reminders. */
function booktrack_health(array $settings):bool
{
    try{
        $db=$settings['db'];
        if(($sqlite=getenv('BOOKTRACK_SQLITE'))!==false&&$sqlite!==''){$db['driver']='sqlite';$db['sqlite_path']=$sqlite;}
        if($db['driver']==='sqlite'&&!is_file($db['sqlite_path']))return false;
        $dsn=$db['driver']==='sqlite'?'sqlite:'.$db['sqlite_path']:sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$db['host'],$db['port'],$db['name']);
        $pdo=new PDO($dsn,$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);
        $pdo->query('SELECT id FROM users LIMIT 1');$pdo->query('SELECT id FROM books LIMIT 1');return true;
    }catch(Throwable $error){return false;}
}
