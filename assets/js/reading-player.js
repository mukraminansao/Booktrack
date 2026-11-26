/* Catalogue read-aloud previews. No recording or microphone access is needed. */
import {listeningStore,rememberListeningBook} from './listening-preferences.js';
(() => {
  'use strict';
  const player = document.querySelector('[data-reading-player]');
  if (!player) return;
  const chapters = [...document.querySelectorAll('[data-preview-chapter]')];
  const play = player.querySelector('[data-preview-play]');
  const position = player.querySelector('[data-preview-position]');
  const time = player.querySelector('[data-preview-time]');
  const status = player.querySelector('[data-preview-status]');
  const rate=player.querySelector('[data-preview-rate]'),volume=player.querySelector('[data-preview-volume]');
  const book=player.querySelector('[data-preview-book]'),memberId=document.body.dataset.memberId;
  let storage;try{storage=window.localStorage;}catch{}
  const preferences=listeningStore(storage,memberId,document.body.dataset.appBase),settings=preferences.settings();
  const saved=preferences.load(book.dataset.previewBook,book.dataset.previewRevision);
  rate.value=String(settings.rate);volume.value=String(settings.volume);
  if (!play || !chapters.length) return;
  if (!('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) {
    status.textContent = 'Read-aloud is unavailable in this browser. Open the book to explore it.';
    return;
  }
  const synth = window.speechSynthesis;
  let index = 0, generation = 0, utterance = null, state = 'idle';
  let elapsed = 0, startedAt = 0, interval = null;
  const currentElapsed=()=>elapsed+(state==='playing'?performance.now()-startedAt:0);
  const persist=()=>preferences.save(book.dataset.previewBook,book.dataset.previewRevision,{chapter:index,position:Number(position.value),elapsed:currentElapsed()});
  const remember=()=>rememberListeningBook(book.dataset.previewBook,memberId,document.body.dataset.appBase);
  const text = () => chapters[index].dataset.previewText;
  const announce = (message, error = false) => {
    status.textContent = message;
    status.toggleAttribute('data-ready', !error);
  };
  const progress = value => {
    position.value = String(value);
    position.style.setProperty('--preview-progress', value + '%');
    position.setAttribute('aria-valuetext', Math.round(value) + ' percent of preview');
  };
  const clock = () => {
    const seconds = Math.floor((elapsed + (state === 'playing' ? performance.now() - startedAt : 0)) / 1000);
    time.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  };
  const setState = next => {
    if (state === 'playing') elapsed += performance.now() - startedAt;
    clearInterval(interval);
    state = next;
    if (state === 'playing') {
      startedAt = performance.now();
      interval = setInterval(clock, 250);
    }
    play.setAttribute('aria-pressed', String(state === 'playing'));
    play.setAttribute('aria-label', state === 'playing' ? 'Pause book preview' : 'Play book preview');
    clock();
  };
  const select = chapter => {
    index = chapter;
    chapters.forEach((button, i) => {
      button.closest('li').classList.toggle('is-current', i === index);
      if (i === index) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
    });
  };
  const stop = () => {
    generation++;
    synth.cancel();
    utterance = null;
    setState('idle');
  };
  const speak = (percent = 0, resumeElapsed = 0) => {
    stop();
    if (percent >= 100) {percent = 0;resumeElapsed=0;}
    const whole = text();
    let offset = Math.floor(whole.length * percent / 100);
    // Seek to a word boundary instead of speaking half a word.
    if (offset > 0) {
      const boundary = whole.indexOf(' ', offset);
      offset = boundary < 0 ? 0 : boundary + 1;
    }
    const run = generation;
    utterance = new SpeechSynthesisUtterance(whole.slice(offset));
    utterance.lang = 'en-US';
    utterance.rate = Number(rate.value);
    utterance.volume = Number(volume.value);
    const localVoice = synth.getVoices().find(voice => voice.localService && /^en\b/i.test(voice.lang));
    if (localVoice) utterance.voice = localVoice;
    progress(offset / whole.length * 100);
    elapsed = resumeElapsed;
    remember();
    utterance.onstart = () => {
      if (run !== generation) return;
      setState('playing');
      persist();
      announce('Playing ' + chapters[index].querySelector('strong').textContent);
    };
    utterance.onboundary = event => {
      if (run === generation) {progress(Math.min(99, (offset + event.charIndex) / whole.length * 100));persist();}
    };
    utterance.onend = () => {
      if (run !== generation) return;
      setState('ended');
      progress(100);
      announce('Preview finished. Choose another chapter or play again.');
      utterance = null;
      persist();
    };
    utterance.onerror = event => {
      if (run !== generation || event.error === 'canceled' || event.error === 'interrupted') return;
      setState('idle');
      announce('Read-aloud could not start. Check that a speech voice is installed, or open the book details.', true);
    };
    setState('starting');
    synth.speak(utterance);
  };
  play.addEventListener('click', () => {
    if (state === 'playing') {
      synth.pause();
      setState('paused');
      announce('Preview paused.');
      persist();
    } else if (state === 'paused') {
      synth.resume();
      setState('playing');
      announce('Preview resumed.');
    } else speak(state === 'ended' ? 0 : Number(position.value),state === 'ended'?0:elapsed);
  });
  const changeChapter = next => { stop(); select(next); progress(0); elapsed = 0; speak(); };
  player.querySelector('[data-preview-prev]').addEventListener('click', () => changeChapter((index + chapters.length - 1) % chapters.length));
  player.querySelector('[data-preview-next]').addEventListener('click', () => changeChapter((index + 1) % chapters.length));
  chapters.forEach((chapter, i) => chapter.addEventListener('click', () => changeChapter(i)));
  position.addEventListener('input', () => position.style.setProperty('--preview-progress', position.value + '%'));
  position.addEventListener('change', () => {
    if (state === 'playing') speak(Number(position.value));
    else {stop();elapsed=0;clock();persist();announce('Preview position saved. Press play to continue.');}
  });
  const changeSettings=()=>{preferences.saveSettings({rate:Number(rate.value),volume:Number(volume.value)});if(state==='playing')speak(Number(position.value),currentElapsed());};
  rate.addEventListener('change',changeSettings);volume.addEventListener('input',changeSettings);
  window.addEventListener('pagehide',()=>{persist();stop();});
  [play, position, rate, volume, ...player.querySelectorAll('[data-preview-prev], [data-preview-next], [popovertarget="preview-listening-options"]'), ...chapters].forEach(control => { control.disabled = false; });
  select(saved&&saved.chapter<chapters.length?saved.chapter:0);
  if(saved){progress(saved.position);elapsed=saved.elapsed;clock();}
  announce(saved&&saved.position>0&&saved.position<100?'Your preview position is saved. Press play to continue.':'Ready to listen. These chapters read the catalogue preview, not the full audiobook.');
})();
