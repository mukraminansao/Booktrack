      <div class="reading-player" data-preview-book="<?= (int)$previewBook['id'] ?>" data-preview-revision="<?= e(hash('sha256',json_encode(catalogue_preview_chapters($previewBook)))) ?>">
        <a class="reading-player-jacket" href="<?= e(url('book.php',['id'=>(int)$previewBook['id']])) ?>" aria-label="View <?= e($previewBook['title']) ?>"><?= cover_html($previewBook, 'reading-utility-cover') ?></a>
        <div class="reading-player-body">
          <a class="reading-player-title" href="<?= e(url('book.php',['id'=>(int)$previewBook['id']])) ?>"><?= e($previewBook['title']) ?></a>
          <div class="preview-author-row"><p class="reading-player-author"><?= e($previewBook['author']) ?></p><label class="audio-speed"><span class="sr-only">Preview speed</span><select data-preview-rate aria-label="Preview speed" disabled><?php foreach([.5,.75,.95,1,1.25,1.5,1.75,2] as $speed): ?><option value="<?= $speed ?>" <?= $speed===.95?'selected':'' ?>><?= $speed ?>×</option><?php endforeach; ?></select></label><button class="listening-settings-button" type="button" popovertarget="preview-listening-options" aria-label="Listening settings" disabled><?= icon('settings') ?></button></div>
          <div class="reading-player-progress"><label class="sr-only" for="preview-position">Preview position</label><input type="range" id="preview-position" min="0" max="100" value="0" data-preview-position disabled></div>
          <div class="reading-player-bottom">
            <span class="reading-player-time" data-preview-time>0:00</span>
            <div class="reading-player-controls">
              <button type="button" data-preview-prev aria-label="Previous chapter" disabled><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5v14l-8-7zM21 5v14l-8-7z"/></svg></button>
              <button type="button" class="reading-player-play" data-preview-play aria-label="Play book preview" aria-pressed="false" disabled><svg class="icon preview-play-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 11 7-11 7z"/></svg><svg class="icon preview-pause-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5h3v14H8zM15 5h3v14h-3z"/></svg></button>
              <button type="button" data-preview-next aria-label="Next chapter" disabled><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5v14l8-7zM13 5v14l8-7z"/></svg></button>
            </div>
            <span class="reading-player-kind">Read-aloud preview</span>
          </div>
        </div>
      </div>
      <div class="listening-options" id="preview-listening-options" popover aria-label="Listening settings"><label>Volume <input data-preview-volume type="range" min="0" max="1" step=".05" value="1" aria-label="Preview volume" disabled></label></div>
      <p class="reading-player-status" data-preview-status role="status">Enable JavaScript to listen to this catalogue preview.</p>
