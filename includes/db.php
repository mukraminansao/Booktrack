<?php
/**
 * Database access (PDO). One shared connection per request.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $c = config('db');
    try {
        if ($c['driver'] === 'sqlite') {
            $pdo = new PDO('sqlite:' . $c['sqlite_path']);
            $pdo->setAttribute(PDO::ATTR_TIMEOUT,(int)config('db.sqlite_timeout',5));
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
            $pdo = new PDO($dsn, $c['user'], $c['pass']);
            // keep MySQL's clock in step with PHP's timezone
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        }
    } catch (PDOException $e) {
        database_unavailable($e);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

function database_unavailable(Throwable $e): never
{
        if(PHP_SAPI==='cli'){error_log('BookTrack database unavailable: '.$e->getMessage());exit(1);}
        http_response_code(503);
        header('Cache-Control: no-store, max-age=0');
        header('Retry-After: 10');
        $hint = config('debug')
            ? htmlspecialchars($e->getMessage())
            : 'Please try again later.';
        error_log('BookTrack database connection: '.$e->getMessage());
        if (wants_json()) json_out(['ok'=>false,'error'=>'The library database is temporarily unavailable. Please try again shortly.'],503);
        $missingDatabase = (int)($e->errorInfo[1] ?? 0) === 1049;
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Library temporarily unavailable | BookTrack</title>'
            . '<body style="margin:0;background:#f1efe3;color:#201f1a;font-family:system-ui,sans-serif">'
            . '<main style="max-width:560px;margin:12vh auto;padding:32px;line-height:1.6;background:#fffdf9;border:1px solid #e3dbcd;border-radius:24px">'
            . '<p>BookTrack</p><h1 style="font:normal 36px Georgia,serif;line-height:1.1">Your library will be back shortly</h1>'
            . '<p>The database connection is unavailable. For a local XAMPP installation, start MySQL in the control panel, then retry this page. Your books and account stay on this server.</p>'
            . '<p><a style="display:inline-block;padding:10px 22px;background:#303123;color:#fffdf9;border-radius:24px;text-decoration:none" href="'.htmlspecialchars(url('discover.php')).'">Try again</a></p>'
            . ($missingDatabase ? '<p>First installation? <a href="'.htmlspecialchars(url('setup.php')).'">Open setup</a>.</p>' : '')
            . (config('debug') ? '<details><summary>Connection details</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.$hint.'</pre></details>' : '')
            . '</main></body></html>');
}

function db_is_mysql(): bool
{
    return config('db.driver') !== 'sqlite';
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    if(function_exists('with_library_write')&&!preg_match('/^\s*(SELECT|SHOW|PRAGMA|EXPLAIN)\b/i',$sql))return with_library_write(function()use($sql,$params){$stmt=db()->prepare($sql);$stmt->execute($params);return $stmt;});
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Row-lock clause for SELECTs inside a transaction (MySQL only). */
function for_update(): string
{
    return db_is_mysql() ? ' FOR UPDATE' : '';
}

function transaction(callable $fn)
{
    if(function_exists('with_library_write'))return with_library_write(fn()=>database_transaction($fn));
    return database_transaction($fn);
}
function database_transaction(callable $fn)
{
    $pdo = db();
    if($pdo->inTransaction()){
        static $sequence=0;$savepoint='bt_nested_'.(++$sequence);$pdo->exec('SAVEPOINT '.$savepoint);
        try{$result=$fn($pdo);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);return $result;}
        catch(Throwable $e){$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);throw $e;}
    }
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
