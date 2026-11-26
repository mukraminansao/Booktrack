<div class="audiobook" data-audiobook data-progress-version="<?= e(progress_version((int)current_user()['id'],(int)$listeningBook['id'],'audio')) ?>" data-revision="<?= e(reading_revision((int)$listeningBook['id'],'audio')) ?>" data-book-id="<?= (int)$listeningBook['id'] ?>" data-api="<?= e(url('api/reading.php')) ?>" data-position="<?= (float)($audioProgress['position'] ?? 0) ?>" data-rate="<?= (float)($audioProgress['rate'] ?? 1) ?>" data-completed="<?= (int)($audioProgress['completed'] ?? 0) ?>">
  <audio data-native-audio preload="metadata" src="<?= e(url('audio.php',['id'=>$listeningBook['id']])) ?>"></audio>
  <div class="reading-player">
    <a class="reading-player-jacket" href="<?= e(url('book.php',['id'=>$listeningBook['id']])) ?>" aria-label="View <?= e($listeningBook['title']) ?>"><?= cover_html($listeningBook,'reading-utility-cover') ?></a>
    <div class="reading-player-body">
      <a class="reading-player-title" href="<?= e(url('listen.php',['id'=>$listeningBook['id']])) ?>"><?= e($listeningBook['title']) ?></a>
      <div class="audio-author-row"><p class="reading-player-author"><?= e($audioMedia['narrator'] ?: $listeningBook['author']) ?></p><label class="audio-speed"><span class="sr-only">Playback speed</span><select data-audio-rate aria-label="Playback speed" disabled><?php foreach ([.5,.75,1,1.25,1.5,1.75,2] as $speed): ?><option value="<?= $speed ?>" <?= $speed===1 ? 'selected':'' ?>><?= $speed ?>×</option><?php endforeach; ?></select></label><button class="listening-settings-button" type="button" popovertarget="audio-listening-options-<?= (int)$listeningBook['id'] ?>" aria-label="Listening settings"><?= icon('settings') ?></button></div>
      <div class="reading-player-progress"><input type="range" min="0" max="100" value="0" step=".1" data-audio-position disabled aria-label="Audiobook position"></div>
      <div class="reading-player-bottom"><span data-audio-time>0:00</span><div class="reading-player-controls">
        <button type="button" data-audio-back disabled aria-label="Rewind 15 seconds"><?= icon('undo') ?></button>
        <button type="button" class="reading-player-play" data-audio-play disabled aria-label="Play audiobook" aria-pressed="false"><svg class="icon preview-play-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 11 7-11 7z"/></svg><svg class="icon preview-pause-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5h3v14H8zM15 5h3v14h-3z"/></svg></button>
        <button type="button" data-audio-forward disabled aria-label="Forward 15 seconds"><?= icon('arrow-right') ?></button>
      </div><span data-audio-duration>—</span></div>
    </div>
  </div>
  <div class="listening-options" id="audio-listening-options-<?= (int)$listeningBook['id'] ?>" popover aria-label="Listening settings"><label>Volume <input data-listening-volume type="range" min="0" max="1" step=".05" value="1" aria-label="Audiobook volume"></label></div>
  <p class="audio-status" role="status" data-audio-status>Loading audio…</p>
  <?php if ($expanded): ?>
  <div class="audio-actions"><button class="btn btn--outline" type="button" data-audio-bookmark disabled><?= icon('bookmark') ?> Bookmark this moment</button><button class="btn btn--outline" type="button" data-audio-finish disabled><?= !empty($audioProgress['completed']) ? 'Listen again' : 'Mark finished' ?></button></div>
  <div class="audio-navigation"><section><h2>Chapters</h2><ol class="reader-chapters"><?php foreach ($audioChapters as $chapter): ?><li><button type="button" data-audio-seek="<?= (float)$chapter['position'] ?>" data-audio-book="<?= (int)$listeningBook['id'] ?>"><?= e($chapter['title']) ?><span><?= reading_clock((float)$chapter['position']) ?></span></button></li><?php endforeach; ?><?php if (!$audioChapters): ?><li class="muted">Chapter markers will appear when the library adds them.</li><?php endif; ?></ol></section><section><h2>Your bookmarks</h2><ul class="reader-bookmarks" data-audio-bookmarks></ul></section></div>
  <?php endif; ?>
  <noscript><audio controls src="<?= e(url('audio.php',['id'=>$listeningBook['id']])) ?>">Your browser does not support audio playback.</audio><p>Enable JavaScript to save your listening position.</p></noscript>
</div>
