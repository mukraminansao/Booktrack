<?php
/** Additive reading/media features. Existing catalogue and circulation tables stay intact. */
function reading_ensure_schema(): void
{
    try { if (q_val("SELECT setting_value FROM app_settings WHERE setting_key = 'reading_schema'") === '1') return; }
    catch (PDOException $e) { /* First upgrade. */ }
    $statements = [
        "CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NOT NULL)",
        "CREATE TABLE IF NOT EXISTS book_media (book_id INTEGER NOT NULL PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, audio_file VARCHAR(255), narrator VARCHAR(160))",
        "CREATE TABLE IF NOT EXISTS book_chapters (id INTEGER PRIMARY KEY AUTOINCREMENT, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, medium VARCHAR(10) NOT NULL, title VARCHAR(160) NOT NULL, position REAL NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS reading_progress (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, medium VARCHAR(10) NOT NULL, position REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, completed INTEGER NOT NULL DEFAULT 0, rate REAL NOT NULL DEFAULT 1, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, book_id, medium))",
        "CREATE TABLE IF NOT EXISTS reading_bookmarks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, medium VARCHAR(10) NOT NULL, position INTEGER NOT NULL, label VARCHAR(100) NOT NULL, created_at VARCHAR(32) NOT NULL, UNIQUE (user_id, book_id, medium, position))",
        "CREATE TABLE IF NOT EXISTS collections (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(160) NOT NULL, subtitle VARCHAR(240), sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS collection_books (collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, position INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (collection_id, book_id))",
    ];
    foreach ($statements as $sql) {
        if (db_is_mysql()) {
            $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', $sql);
            $sql = str_replace('INTEGER NOT NULL', 'INT UNSIGNED NOT NULL', $sql);
            preg_match_all('/(\w+) INT UNSIGNED NOT NULL(?: PRIMARY KEY)? REFERENCES (\w+)\(id\) ON DELETE CASCADE/', $sql, $refs, PREG_SET_ORDER);
            $sql = preg_replace('/ REFERENCES \w+\(id\) ON DELETE CASCADE/', '', $sql);
            if ($refs) $sql = substr($sql, 0, -1) . ', ' . implode(', ', array_map(fn($r) => "FOREIGN KEY ({$r[1]}) REFERENCES {$r[2]}(id) ON DELETE CASCADE", $refs)) . ')';
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        db()->exec($sql);
    }
    reading_upsert('app_settings', ['setting_key' => 'reading_schema'], ['setting_value' => '1']);
}

/** All callers use literal table/column names, never request-provided identifiers. */
function reading_upsert(string $table, array $keys, array $values): void
{
    $data = $keys + $values;
    $columns = implode(', ', array_keys($data));
    $update = implode(', ', array_map(fn($c) => db_is_mysql() ? "$c = VALUES($c)" : "$c = excluded.$c", array_keys($values)));
    $sql = "INSERT INTO $table ($columns) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ')';
    $sql .= db_is_mysql() ? " ON DUPLICATE KEY UPDATE $update" : ' ON CONFLICT (' . implode(',', array_keys($keys)) . ") DO UPDATE SET $update";
    q($sql, array_values($data));
}

function library_setting(string $key, string $default = ''): string
{
    return (string)(q_val('SELECT setting_value FROM app_settings WHERE setting_key = ?', [$key]) ?? $default);
}

function media_for(int $bookId): ?array { return q_one('SELECT * FROM book_media WHERE book_id = ?', [$bookId]); }
function catalogue_preview_chapters(array $book):array
{
    return [
        ['title'=>'A little about '.$book['title'],'text'=>trim((string)$book['description'])!==''?$book['description']:$book['title'].' by '.$book['author'].'. A synopsis has not been added for this title yet.'],
        ['title'=>'Your next chapter starts here','text'=>$book['title'].' by '.$book['author'].'. '.((int)$book['available']>0?plural((int)$book['available'],'copy','copies').' available to borrow.':'All copies are currently on loan.')],
    ];
}
function audiobook_html(array $listeningBook, bool $expanded = false): string
{
    $audioMedia = media_for((int)$listeningBook['id']);
    if (!$audioMedia || !private_media_path($audioMedia['audio_file'] ?? null,'audio')) return '';
    $audioProgress = progress_for((int)current_user()['id'],(int)$listeningBook['id'],'audio');
    $audioChapters = chapters_for((int)$listeningBook['id'],'audio');
    ob_start(); require APP_ROOT . '/includes/partials/audiobook_player.php'; return ob_get_clean();
}
function chapters_for(int $bookId, string $medium): array
{
    return q_all('SELECT * FROM book_chapters WHERE book_id = ? AND medium = ? ORDER BY position, sort_order, id', [$bookId, $medium]);
}
function progress_for(int $userId, int $bookId, string $medium): ?array
{
    return q_one('SELECT * FROM reading_progress WHERE user_id = ? AND book_id = ? AND medium = ?', [$userId, $bookId, $medium]);
}
function save_reading_progress(int $userId, int $bookId, string $medium, float $position, float $total, bool $completed, float $rate = 1): array
{
    if (!in_array($medium, ['ebook','audio'], true)) throw new DomainException('Choose a valid reading format.');
    if (!is_finite($position) || !is_finite($total) || !is_finite($rate) || $position < 0 || $total <= 0 || ($medium==='ebook' && $total<1) || $total > 100000000 || $position > 100000000) throw new DomainException('Invalid reading position.');
    $book = find_book($bookId);
    if (!$book || ($medium === 'ebook' ? !$book['pdf'] : empty(media_for($bookId)['audio_file']))) throw new DomainException('This reading format is unavailable.');
    if ($medium === 'ebook') { $position = max(1, floor($position)); $total = floor($total); }
    if ($total > 0) $position = min($position, $total);
    $rate = max(.5, min(2, $rate));
    reading_upsert('reading_progress', ['user_id'=>$userId,'book_id'=>$bookId,'medium'=>$medium], ['position'=>$position,'total'=>$total,'completed'=>(int)$completed,'rate'=>$rate,'updated_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s.u')]);
    return progress_for($userId, $bookId, $medium);
}
function bookmarks_for(int $userId, int $bookId, string $medium): array
{
    return q_all('SELECT id, position, label FROM reading_bookmarks WHERE user_id = ? AND book_id = ? AND medium = ? ORDER BY position', [$userId,$bookId,$medium]);
}
function save_reading_bookmark(int $userId, int $bookId, string $medium, int $position, string $label): void
{
    if (!in_array($medium,['ebook','audio'],true) || $position < ($medium === 'ebook' ? 1 : 0) || $position > 100000000 || !find_book($bookId)) throw new DomainException('Invalid bookmark.');
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 100) throw new DomainException('Bookmark labels must contain 1 to 100 characters.');
    $exists = q_val('SELECT id FROM reading_bookmarks WHERE user_id = ? AND book_id = ? AND medium = ? AND position = ?', [$userId,$bookId,$medium,$position]);
    if (!$exists && (int)q_val('SELECT COUNT(*) FROM reading_bookmarks WHERE user_id = ? AND book_id = ? AND medium = ?',[$userId,$bookId,$medium]) >= 100) throw new DomainException('You can keep up to 100 bookmarks per book and format.');
    reading_upsert('reading_bookmarks',['user_id'=>$userId,'book_id'=>$bookId,'medium'=>$medium,'position'=>$position],['label'=>$label,'created_at'=>date('Y-m-d H:i:s')]);
}
function continue_reading(int $userId, string $mediaRoot = APP_ROOT): ?array
{
    $candidates=q_all("SELECT " . book_columns() . ", rp.medium, rp.position, rp.total, rp.rate, bm.audio_file FROM " . books_from()
        . " JOIN reading_progress rp ON rp.book_id=b.id LEFT JOIN book_media bm ON bm.book_id=b.id WHERE rp.user_id=? AND rp.completed=0 ORDER BY rp.updated_at DESC,b.id DESC,rp.medium",[$userId]);
    foreach($candidates as $book){$audio=$book['medium']==='audio';if(private_media_path($audio?$book['audio_file']:$book['pdf'],$audio?'audio':'pdfs',$mediaRoot))return $book;}
    return null;
}
function reading_url(array $book, string $medium = 'ebook'): string
{
    return url($medium === 'audio' ? 'listen.php' : 'reader.php',['id'=>(int)$book['id']]);
}
function reading_revision(int $bookId,string $medium):string
{
    $path=$medium==='ebook'?q_val('SELECT pdf FROM books WHERE id=?',[$bookId]):(media_for($bookId)['audio_file']??'');
    return hash('sha256',(string)$path);
}
/** Reset positions tied to an old edition; never attach old markers to a new file. */
function reading_replace_file(int $bookId,string $medium):void
{
    q('DELETE FROM reading_progress WHERE book_id=? AND medium=?',[$bookId,$medium]);
    q('DELETE FROM reading_bookmarks WHERE book_id=? AND medium=?',[$bookId,$medium]);
    q('DELETE FROM book_chapters WHERE book_id=? AND medium=?',[$bookId,$medium]);
    q('DELETE FROM app_settings WHERE setting_key=?',['media_bound_'.$bookId.'_'.$medium]);
}
function progress_version(int $userId,int $bookId,string $medium):string
{
    return hash('sha256',json_encode(progress_for($userId,$bookId,$medium),JSON_THROW_ON_ERROR));
}
function media_bound(int $bookId,string $medium):?float
{
    $data=json_decode(library_setting('media_bound_'.$bookId.'_'.$medium,'null'),true);
    return is_array($data)&&($data['revision']??'')===reading_revision($bookId,$medium)?(float)$data['total']:null;
}
function remember_media_bound(int $bookId,string $medium,float $total):void
{
    if(!is_finite($total)||$total<=0||$total>100000000||($medium==='ebook'&&floor($total)!==$total))throw new DomainException('Could not verify the file length. Reload the editor and try again.');
    reading_upsert('app_settings',['setting_key'=>'media_bound_'.$bookId.'_'.$medium],['setting_value'=>json_encode(['revision'=>reading_revision($bookId,$medium),'total'=>$total],JSON_THROW_ON_ERROR)]);
}
function chapters_within_file(array $rows,string $medium,?float $total):void
{
    if(!$rows)return;
    if($total===null||!is_finite($total)||$total<=0)throw new DomainException('Verify the file length in this editor before saving chapter markers.');
    foreach($rows as $row)if($row['position']>$total||($medium==='audio'&&$row['position']>=$total))throw new DomainException('A chapter marker is beyond the end of the '.($medium==='ebook'?'PDF.':'recording.'));
}
function edit_version(string $kind,int $id):string
{
    $data=match($kind){
      'books'=>q_one('SELECT * FROM books WHERE id=?',[$id]),
      'members'=>q_one('SELECT id,name,username,email,phone,is_active,password_hash FROM users WHERE id=?',[$id]),
      'features'=>[library_setting('featured_book'),library_setting('featured_audio')],
      'media'=>[media_for($id),reading_revision($id,'ebook'),chapters_for($id,'ebook'),chapters_for($id,'audio')],
      'collection'=>[q_one('SELECT * FROM collections WHERE id=?',[$id]),q_all('SELECT * FROM collection_books WHERE collection_id=? ORDER BY position',[$id])],
      default=>throw new LogicException('Unknown editor')};
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
function check_edit_version(string $kind,int $id):void
{
    $sent=input('version');if($sent===''||!hash_equals(edit_version($kind,$id),$sent))throw new DomainException('This record changed or the editor is outdated. Reload it before saving your changes.');
}
/** Elapsed audiobook time, including books longer than a calendar day. */
function reading_clock(float $seconds): string
{
    $seconds=is_finite($seconds)?max(0,(int)floor($seconds)):0;
    return sprintf('%02d:%02d:%02d',intdiv($seconds,3600),intdiv($seconds%3600,60),$seconds%60);
}
function store_audio(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (demo_uploads_blocked()) throw new DomainException('Uploads are turned off in the public demo.');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new DomainException('The audio could not be uploaded. Check your server upload limit.');
    if ($f['size'] > (int)config('uploads.max_audio_mb',50)*1024*1024) throw new DomainException('The audio file is too large.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $types = ['audio/mpeg'=>'mp3','audio/mp4'=>'m4a','audio/x-m4a'=>'m4a','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/vnd.wave'=>'wav'];
    if (!isset($types[$mime])) throw new DomainException('Choose an MP3, M4A, OGG or WAV audio file.');
    $dir = APP_ROOT . '/uploads/audio';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $path = 'uploads/audio/' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (!move_uploaded_file($f['tmp_name'], APP_ROOT . '/' . $path)) throw new RuntimeException('Could not save the audio file.');
    return $path;
}

function chapter_position(string $input, string $medium): float
{
    $input=trim($input);
    if ($medium==='audio' && preg_match('/^(\d{1,5}):([0-5]\d)$/',$input,$m)) $value=(int)$m[1]*60+(int)$m[2];
    elseif ($medium==='audio' && preg_match('/^(\d{1,5}):([0-5]?\d):([0-5]\d)$/',$input,$m)) $value=(int)$m[1]*3600+(int)$m[2]*60+(int)$m[3];
    elseif (is_numeric($input)) $value=(float)$input;
    else throw new DomainException('Enter a page number, seconds, or an audio time such as 12:30.');
    if (!is_finite($value) || $value<($medium==='ebook'?1:0) || $value>100000000 || ($medium==='ebook' && floor($value)!==$value)) throw new DomainException('Chapter positions must be valid page numbers or audio times.');
    return $value;
}
function validate_chapters(array $titles, array $positions, string $medium): array
{
    if (!in_array($medium,['ebook','audio'],true) || count($titles)>200 || count($titles)!==count($positions)) throw new DomainException('Use at most 200 chapter markers per format.');
    $rows=[]; $seen=[];
    foreach ($titles as $i=>$title) {
        if (!is_string($title) || !is_string($positions[$i] ?? null)) throw new DomainException('Invalid chapter data.');
        $title=trim($title);$pos=trim($positions[$i]);
        if ($title==='' && $pos==='') continue;
        if ($title==='' || mb_strlen($title)>160) throw new DomainException('Every chapter needs a title of at most 160 characters.');
        $position=chapter_position($pos,$medium);
        if (isset($seen[(string)$position])) throw new DomainException('Chapter positions must be different.');
        $seen[(string)$position]=true;$rows[]=['title'=>$title,'position'=>$position];
    }
    usort($rows,fn($a,$b)=>$a['position']<=>$b['position']);return $rows;
}
function replace_chapters(int $bookId, string $medium, array $rows): void
{
    q('DELETE FROM book_chapters WHERE book_id=? AND medium=?',[$bookId,$medium]);
    foreach ($rows as $i=>$row) q('INSERT INTO book_chapters (book_id,medium,title,position,sort_order) VALUES (?,?,?,?,?)',[$bookId,$medium,$row['title'],$row['position'],$i]);
}

/** Realpath containment includes the trailing slash (not just a matching prefix). */
function private_media_path(?string $relative, string $folder, string $mediaRoot = APP_ROOT): ?string
{
    if (!$relative || !str_starts_with($relative, "uploads/$folder/")) return null;
    $path = realpath($mediaRoot . '/' . $relative);
    $root = realpath($mediaRoot . "/uploads/$folder");
    $uploads=realpath($mediaRoot.'/uploads');
    if(!$uploads||dirname($uploads)!==realpath($mediaRoot)||!$root||dirname($root)!==$uploads||is_link($mediaRoot."/uploads/$folder"))return null;
    return $path && $root && is_file($path) && str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/') ? $path : null;
}

/** HTTP range support allows seeking in long recordings without downloading them again. */
function stream_private_audio(string $path): void
{
    $mime = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4','ogg'=>'audio/ogg','wav'=>'audio/wav'][pathinfo($path,PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    stream_private_file($path,$mime);
}

/** Shared PDF/audio streaming keeps seeking efficient and releases the account lock. */
function stream_private_file(string $path, string $mime): void
{
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    $size = filesize($path); $start = 0; $end = $size - 1;
    $etag='"'.hash('sha256',$path.':'.filemtime($path).':'.$size).'"';
    header('ETag: '.$etag);header('Cache-Control: private, no-cache, must-revalidate');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')===$etag){http_response_code(304);exit;}
    if ($method==='GET' && isset($_SERVER['HTTP_RANGE']) && (!isset($_SERVER['HTTP_IF_RANGE']) || $_SERVER['HTTP_IF_RANGE']===$etag)) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$m) || ($m[1] === '' && $m[2] === '')) {
            http_response_code(416); header("Content-Range: bytes */$size"); exit;
        }
        if ($m[1] === '') $start = max(0,$size-(int)$m[2]);
        else { $start=(int)$m[1]; if ($m[2] !== '') $end=min($end,(int)$m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206); header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Type: '.$mime); header('Accept-Ranges: bytes'); header('Content-Length: '.($end-$start+1));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($method === 'HEAD') exit;
    $file=fopen($path,'rb'); fseek($file,$start); $remaining=$end-$start+1;
    while ($remaining>0 && !feof($file) && !connection_aborted()) { $chunk=fread($file,min(65536,$remaining)); if ($chunk===false || $chunk==='') break; echo $chunk; $remaining-=strlen($chunk); }
    fclose($file); exit;
}
