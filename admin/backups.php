<?php
require __DIR__.'/../includes/bootstrap.php';require_once __DIR__.'/../includes/backups.php';$user=require_role('admin');$error=null;$review=null;
if(demo_mode()){flash('error','Backup downloads and restore are disabled on the public demo.');redirect('admin/index.php');}
header('Cache-Control: no-store');backup_cleanup_reviews();
if(!empty($_SESSION['restore_pending'])&&($_SESSION['restore_expires']??0)<time()){backup_discard_review($_SESSION['restore_pending']);unset($_SESSION['restore_pending'],$_SESSION['restore_expires']);}
if(input('download')!==''){
 $name=input('download');if(!preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{12}\.tar$/D',$name)||!is_file($file=backup_directory().'/'.$name)){http_response_code(404);exit('Backup not found.');}
 header('Content-Type: application/x-tar');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.filesize($file));header('Cache-Control: no-store');session_write_close();readfile($file);exit;
}
if(is_post()){
 verify_csrf();try{
  if(input('action')==='create'){$file=backup_create();log_activity('backup.created',basename($file));flash('success','Backup created. Download it from the list below.');redirect('admin/backups.php');}
  if(input('action')==='review'){
   $f=$_FILES['backup_file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)throw new DomainException('Choose a TAR backup within your server upload limit.');
   $path=backup_directory().'/pending-'.bin2hex(random_bytes(16)).'.tar';if(!move_uploaded_file($f['tmp_name'],$path))throw new RuntimeException('Could not store the backup for review.');
   try{$review=backup_validate($path);}catch(Throwable $e){unlink($path);throw $e;}
   backup_discard_review($_SESSION['restore_pending']??'');
   $_SESSION['restore_pending']=basename($path);$_SESSION['restore_expires']=time()+1800;
  }elseif(input('action')==='cancel_review'){
   backup_discard_review($_SESSION['restore_pending']??'');unset($_SESSION['restore_pending'],$_SESSION['restore_expires']);flash('success','Restore review cancelled. Your library has not been changed.');redirect('admin/backups.php');
  }elseif(input('action')==='restore'){
   if(input('confirmation')!=='RESTORE')throw new DomainException('Type RESTORE to confirm the replacement.');
   $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');if(login_locked_for($user['username'],$ip))throw new DomainException('Too many attempts. Wait a few minutes and try again.');
   if(!password_verify((string)($_POST['password']??''),(string)q_val('SELECT password_hash FROM users WHERE id=?',[$user['id']]))){login_attempts_sql('INSERT INTO login_attempts (username,ip,attempted_at) VALUES (?,?,?)',[$user['username'],$ip,time()]);throw new DomainException('The librarian password was not correct.');}
   $pending=$_SESSION['restore_pending']??'';
   if(!preg_match('/^pending-[a-f0-9]{32}\.tar$/D',$pending)||($_SESSION['restore_expires']??0)<time())throw new DomainException('This restore review has expired. Upload the backup again.');
   $path=backup_directory().'/'.$pending;backup_restore($path);unlink($path);logout();redirect('login.php',['restored'=>'1']);
  }
 }catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'The backup operation could not finish. Existing saved backups are retained. Check the server log for recovery details.';if(!$e instanceof DomainException)error_log('BookTrack backup: '.$e->getMessage());}
}
if(!$review&&!empty($_SESSION['restore_pending'])&&($_SESSION['restore_expires']??0)>=time()){try{$review=backup_validate(backup_directory().'/'.$_SESSION['restore_pending']);}catch(Throwable $e){unset($_SESSION['restore_pending']);}}
$backups=glob(backup_directory().'/backup-*.tar')?:[];rsort($backups);$title='Backups';$active='backups';require __DIR__.'/../includes/partials/header.php';
?>
<header class="page-head"><div class="page-head__title"><h1>Backups & recovery</h1><p>Keep a portable copy of your library database and original book files.</p></div><a class="btn btn--outline" href="<?= e(url('admin/content.php')) ?>">Content & audio</a></header>
<?php if($error): ?><div class="alert alert--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="enhancement-grid"><section class="card stack"><h2>Create a backup</h2><p class="muted">Includes accounts, loans, messages, reviews, reading progress, collections, and original covers, PDFs and recordings. Configuration passwords and generated cover previews are excluded.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><button class="btn" type="submit" data-busy="Creating backup…">Create backup</button></form><p class="hint">These TAR downloads are private, unencrypted records. Keep the separate installation key safe: it is needed to recover two-step sign-in. Administrators can use the encrypted export and separate-copy commands documented in HARDENING.md.</p></section>
<section class="card stack"><h2>Review a backup to restore</h2><p class="muted">Upload a BookTrack TAR backup to inspect its date and record counts. Your current library is only replaced after the review and password confirmation.</p><form method="post" enctype="multipart/form-data" class="enhancement-form"><?= csrf_field() ?><input type="hidden" name="action" value="review"><label class="field" for="backup-file">BookTrack backup<input class="input" id="backup-file" name="backup_file" type="file" accept=".tar,application/x-tar" required></label><span class="hint">Server upload limit: <?= e(ini_get('upload_max_filesize')) ?>; total form limit: <?= e(ini_get('post_max_size')) ?>. Use the command-line tool for larger archives.</span><button class="btn btn--outline" type="submit">Review backup</button></form></section></div>
<?php if($review): ?><section class="card stack"><h2>Restore review</h2><p>Created <?= e($review['manifest']['created_at']??'Unknown') ?> · <?= e(format_bytes($review['bytes'])) ?> · <?= count($review['manifest']['files']) ?> attachments</p><dl class="backup-counts"><?php foreach($review['counts'] as $table=>$count): ?><div><dt><?= e(str_replace('_',' ',$table)) ?></dt><dd><?= $count ?></dd></div><?php endforeach; ?></dl><p class="alert alert--warn">Restoring replaces the current library records and matching attachments. A safety backup is created first. Everyone will need to sign in again using accounts from the restored backup.</p><form method="post" class="enhancement-form"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><label class="field">Current librarian password<input class="input" type="password" name="password" autocomplete="current-password" required></label><label class="field">Type RESTORE<input class="input" name="confirmation" autocomplete="off" pattern="RESTORE" required></label><button class="btn btn--danger" type="submit" data-busy="Restoring…">Restore this backup</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_review"><button class="btn btn--outline" type="submit">Cancel review</button></form></section><?php endif; ?>
<section class="card stack"><h2>Saved backups</h2><?php if(!$backups): ?><p class="muted">No backups yet. Create your first copy above.</p><?php endif; ?><ul class="backup-list"><?php foreach($backups as $file): ?><li><span><strong><?= e(basename($file)) ?></strong><span class="small muted"><?= e(format_bytes(filesize($file))) ?></span></span><a class="btn btn--outline" href="<?= e(url('admin/backups.php',['download'=>basename($file)])) ?>">Download</a></li><?php endforeach; ?></ul></section>
<?php require __DIR__.'/../includes/partials/footer.php'; ?>
