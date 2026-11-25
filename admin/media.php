<?php
require __DIR__.'/../includes/bootstrap.php';require_role('admin');
$book=find_book(input_int('id'));if (!$book){flash('error','Choose a book to manage its audio and chapters.');redirect('admin/books.php');}
$media=media_for((int)$book['id']);$error=null;
if (is_post()) {
    verify_csrf();$uploaded=null;$committed=false;
    try {
        check_edit_version('media',(int)$book['id']);$narrator=input('narrator');if (mb_strlen($narrator)>160) throw new DomainException('Narrator names can contain at most 160 characters.');
        $audioRows=validate_chapters((array)($_POST['audio_title'] ?? []),(array)($_POST['audio_position'] ?? []),'audio');
        $ebookRows=validate_chapters((array)($_POST['ebook_title'] ?? []),(array)($_POST['ebook_position'] ?? []),'ebook');
        $uploaded=store_audio('audio_file');$path=$uploaded ?? (input('remove_audio')==='1' ? null : ($media['audio_file'] ?? null));
        if(!$path&&input('remove_audio')==='1')$audioRows=[];
        $audioTotal=$path&&is_numeric(input('audio_total'))?(float)input('audio_total'):null;
        $ebookTotal=$book['pdf']&&is_numeric(input('ebook_total'))?(float)input('ebook_total'):null;
        chapters_within_file($path?$audioRows:[],'audio',$audioTotal);chapters_within_file($ebookRows,'ebook',$ebookTotal);
        if(!$path&&$audioRows)throw new DomainException('Upload a recording before adding audio chapters.');
        transaction(function()use($book,$media,$narrator,$path,$audioRows,$ebookRows,$audioTotal,$ebookTotal){
            if(($media['audio_file']??null)!==$path)reading_replace_file((int)$book['id'],'audio');
            reading_upsert('book_media',['book_id'=>(int)$book['id']],['audio_file'=>$path,'narrator'=>$narrator ?: null]);
            if($audioTotal!==null)remember_media_bound((int)$book['id'],'audio',$audioTotal);
            if($ebookTotal!==null)remember_media_bound((int)$book['id'],'ebook',$ebookTotal);
            replace_chapters((int)$book['id'],'audio',$audioRows);replace_chapters((int)$book['id'],'ebook',$ebookRows);log_activity('book.media','Audio and chapters for '.$book['title']);});
        $committed=true;
        if (($media['audio_file'] ?? null)!==$path) delete_upload($media['audio_file'] ?? null);
        flash('success','Audio and chapters saved.');redirect('admin/media.php',['id'=>$book['id']]);
    }catch(Throwable $e){if($uploaded&&!$committed)delete_upload($uploaded);$error=$e instanceof DomainException?$e->getMessage():'Audio and chapters could not be saved. Please try again.';if(!$e instanceof DomainException)error_log('BookTrack media: '.$e->getMessage());}
}
$title='Audio & chapters';$active='content';require __DIR__.'/../includes/partials/header.php';
?>
<a class="back-link" href="<?= e(url('admin/books.php')) ?>"><?= icon('arrow-left') ?> Back to books</a>
<header class="page-head"><div class="page-head__title"><h1>Audio & chapters</h1><p><?= e($book['title']) ?> · <?= e($book['author']) ?></p></div><a class="btn btn--outline" href="<?= e(url('book.php',['id'=>$book['id']])) ?>">View book</a></header>
<?php if($error): ?><div class="alert alert--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="enhancement-form" data-chapter-editor data-pdf="<?= $book['pdf']?e(url('read.php',['id'=>$book['id']])):'' ?>" data-audio="<?= !empty($media['audio_file'])?e(url('audio.php',['id'=>$book['id']])):'' ?>">
<input type="hidden" name="ebook_total"><input type="hidden" name="audio_total">
<?= csrf_field() ?><input type="hidden" name="version" value="<?= e(input('version',edit_version('media',(int)$book['id']))) ?>"><input type="hidden" name="id" value="<?= (int)$book['id'] ?>">
<section class="card stack"><h2>Audiobook recording</h2><p class="muted">Upload a recording the library is allowed to share. Members can resume, change speed, and bookmark moments.</p>
<p class="hint">Replacing or removing a file resets members' saved places and bookmarks for that format. Removing a recording clears its chapters. Review chapter markers against a replacement file before saving.</p><p class="hint" data-media-metadata role="status">File lengths will be checked when you save.</p>
<div class="field"><label for="audio-upload">MP3, M4A, OGG or WAV</label><input class="input" type="file" id="audio-upload" name="audio_file" accept="audio/mpeg,audio/mp4,audio/ogg,audio/wav"><span class="hint">Application limit: <?= (int)config('uploads.max_audio_mb',50) ?> MB. Server upload limit: <?= e(ini_get('upload_max_filesize')) ?>; total form limit: <?= e(ini_get('post_max_size')) ?>.</span></div>
<div class="field"><label for="narrator">Narrator (optional)</label><input class="input" id="narrator" name="narrator" maxlength="160" value="<?= e(is_post()?input('narrator'):($media['narrator'] ?? '')) ?>"></div>
<?php $currentAudio=private_media_path($media['audio_file']??null,'audio');if($currentAudio): ?><p><a class="link" href="<?= e(reading_url($book,'audio')) ?>">Listen to current recording</a> · <?= e(format_bytes((int)filesize($currentAudio))) ?></p><label><input type="checkbox" name="remove_audio" value="1"> Remove the current recording</label><?php endif; ?>
</section>
<div class="enhancement-grid">
<?php foreach(['ebook'=>'E-book chapters','audio'=>'Audio chapters'] as $medium=>$label):
 $rows=chapters_for((int)$book['id'],$medium);
 if(is_post()){
  $titles=(array)($_POST[$medium.'_title']??[]);$positions=(array)($_POST[$medium.'_position']??[]);$rows=[];
  foreach(array_slice(array_keys($titles),0,200) as $key)$rows[]=['title'=>is_scalar($titles[$key])?(string)$titles[$key]:'','position'=>is_scalar($positions[$key]??null)?(string)$positions[$key]:''];
 }
 if(count($rows)<200)$rows[]=['title'=>'','position'=>''];
?>
<section class="card stack" data-chapter-group="<?= $medium ?>"><h2><?= $label ?></h2><p class="muted small"><?= $medium==='ebook' ? 'Use PDF page numbers starting at 1.' : 'Use seconds or a time such as 03:45 or 1:03:45.' ?> Clear both fields to remove a marker.</p>
<div data-chapter-rows><?php foreach($rows as $i=>$row): ?><div class="chapter-edit-row"><label class="field">Title<input class="input" name="<?= $medium ?>_title[]" maxlength="160" value="<?= e($row['title']) ?>" aria-label="<?= $label ?> title <?= $i+1 ?>"></label><label class="field"><?= $medium==='ebook'?'Page':'Time' ?><input class="input" name="<?= $medium ?>_position[]" value="<?= e($row['position']) ?>" aria-label="<?= $label ?> position <?= $i+1 ?>"></label><button class="btn btn--ghost" type="button" data-remove-chapter aria-label="Remove chapter row">Remove</button></div><?php endforeach; ?></div>
<button class="btn btn--outline" type="button" data-add-chapter <?= count($rows)>=200?'disabled':'' ?>>Add chapter</button></section>
<?php endforeach; ?></div><button class="btn" type="submit">Save audio and chapters</button>
</form><script type="module" src="<?= e(asset('js/content-editor.js')) ?>"></script>
<?php require __DIR__.'/../includes/partials/footer.php'; ?>
