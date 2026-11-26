import {readingState,drawBookmarks} from './reading-state.js';
import {listeningStore,rememberListeningBook} from './listening-preferences.js';
const stamp=seconds=>{seconds=Number.isFinite(seconds)?Math.max(0,Math.floor(seconds)):0;return (seconds>=3600?Math.floor(seconds/3600)+':':'')+String(Math.floor(seconds/60)%60).padStart(seconds>=3600?2:1,'0')+':'+String(seconds%60).padStart(2,'0');};
for(const root of document.querySelectorAll('[data-audiobook]')){
  let storage;try{storage=window.localStorage;}catch{}
  const preferences=listeningStore(storage,document.body.dataset.memberId,document.body.dataset.appBase);
  const audio=root.querySelector('audio'),play=root.querySelector('[data-audio-play]'),range=root.querySelector('[data-audio-position]'),rate=root.querySelector('[data-audio-rate]'),statusEl=root.querySelector('[data-audio-status]');
  audio.volume=preferences.settings().volume;
  const volume=root.querySelector('[data-listening-volume]');
  if(volume){volume.value=String(audio.volume);volume.addEventListener('input',()=>{audio.volume=Number(volume.value);preferences.saveSettings({volume:audio.volume});});}
  const status=(message,error=false)=>{statusEl.textContent=message;statusEl.classList.toggle('is-error',error);statusEl.toggleAttribute('data-ready',!error);};
  const state=readingState(root,'audio',status);let completed=root.dataset.completed==='1',lastSaved=0,ready=false,loading=false;
  const save=(immediate=false)=>{if(ready&&Number.isFinite(audio.duration)&&audio.duration>0)return state.save({position:audio.currentTime,total:audio.duration,completed:completed?'1':'0',rate:audio.playbackRate},immediate);};
  const finishLabel=()=>{const button=root.querySelector('[data-audio-finish]');if(button)button.textContent=completed?'Listen again':'Mark finished';};
  const update=()=>{root.querySelector('[data-audio-time]').textContent=stamp(audio.currentTime);root.querySelector('[data-audio-duration]').textContent=stamp(audio.duration);const p=audio.duration?audio.currentTime/audio.duration*100:0;range.value=p;range.style.setProperty('--preview-progress',p+'%');range.setAttribute('aria-valuetext',stamp(audio.currentTime)+' of '+stamp(audio.duration));document.querySelectorAll('[data-audio-seek][data-audio-book="'+root.dataset.bookId+'"]').forEach((button,i,all)=>{const current=audio.currentTime>=Number(button.dataset.audioSeek)&&(!all[i+1]||audio.currentTime<Number(all[i+1].dataset.audioSeek));button.classList.toggle('is-current',current);if(current)button.setAttribute('aria-current','step');else button.removeAttribute('aria-current');});};
  const seek=position=>{if(!ready)return;audio.currentTime=Math.max(0,Math.min(audio.duration,position));update();save(true);};
  const list=root.querySelector('[data-audio-bookmarks]');
  const bookmarks=rows=>{if(list)drawBookmarks(list,rows,seek,async id=>{try{bookmarks((await state.remove(id)).bookmarks);status('Bookmark removed.');}catch(e){status(e.message,true);}});};
  const metadata=async()=>{
    if(ready||loading)return;
    if(!Number.isFinite(audio.duration)||audio.duration<=0){status('The recording duration is unavailable. Reload to try again.',true);return;}
    loading=true;let loadFailed=false;
    let position=Number(root.dataset.position)||0,speed=Number(root.dataset.rate)||1;
    try{const data=await state.load();bookmarks(data.bookmarks);if(data.progress){position=Number(data.progress.position);speed=Number(data.progress.rate);completed=Number(data.progress.completed)===1;}}catch(e){loadFailed=true;if(list){const li=document.createElement('li');li.className='muted';li.textContent='Your bookmarks could not load. Refresh to try again.';list.replaceChildren(li);}status('Could not load your saved position. Playback is still available.',true);}
    audio.playbackRate=Math.max(.5,Math.min(2,speed));rate.value=String(audio.playbackRate);audio.currentTime=position>=audio.duration?0:Math.max(0,position);
    ready=true;loading=false;root.querySelectorAll('button,input,select').forEach(b=>b.disabled=false);document.querySelectorAll('[data-audio-seek][data-audio-book="'+root.dataset.bookId+'"]').forEach(b=>{if(Number(b.dataset.audioSeek)>=audio.duration){b.disabled=true;b.title='This chapter is outside the current recording.';}});const finish=root.querySelector('[data-audio-finish]');if(finish)finish.textContent=completed?'Listen again':'Mark finished';update();if(!loadFailed)status('Ready. Your listening position is saved to your account.');
  };
  audio.addEventListener('loadedmetadata',metadata);if(audio.readyState>=1)metadata();
  audio.addEventListener('durationchange',()=>{if(Number.isFinite(audio.duration)&&audio.duration>0)metadata();});
  audio.addEventListener('error',()=>status('This recording could not load. Try again later or contact the library.',true));
  audio.addEventListener('play',()=>{rememberListeningBook(root.dataset.bookId,document.body.dataset.memberId,document.body.dataset.appBase);play.setAttribute('aria-pressed','true');play.setAttribute('aria-label','Pause audiobook');status('Playing audiobook.');});
  audio.addEventListener('pause',()=>{play.setAttribute('aria-pressed','false');play.setAttribute('aria-label','Play audiobook');save(true);});
  audio.addEventListener('timeupdate',()=>{update();if(performance.now()-lastSaved>5000){lastSaved=performance.now();save(true);}});
  audio.addEventListener('ended',async()=>{completed=true;finishLabel();if(await save(true))status('Audiobook finished.');});
  audio.addEventListener('seeked',()=>save(true));audio.addEventListener('ratechange',()=>save(true));
  play.addEventListener('click',async()=>{if(!audio.paused){audio.pause();return;}document.querySelectorAll('audio').forEach(a=>{if(a!==audio)a.pause();});try{if(completed||audio.ended){completed=false;audio.currentTime=0;finishLabel();save(true);}await audio.play();}catch(e){status('Playback could not start. Press play to try again.',true);}});
  range.addEventListener('change',()=>seek(Number(range.value)/100*audio.duration));rate.addEventListener('change',()=>{audio.playbackRate=Number(rate.value);preferences.saveSettings({rate:audio.playbackRate});});
  root.querySelector('[data-audio-back]').addEventListener('click',()=>seek(audio.currentTime-15));root.querySelector('[data-audio-forward]').addEventListener('click',()=>seek(audio.currentTime+15));
  document.querySelectorAll('[data-audio-seek][data-audio-book="'+root.dataset.bookId+'"]').forEach(b=>b.addEventListener('click',()=>seek(Number(b.dataset.audioSeek))));
  root.querySelector('[data-audio-bookmark]')?.addEventListener('click',async()=>{try{bookmarks((await state.bookmark(audio.currentTime,'At '+stamp(audio.currentTime))).bookmarks);status('Listening bookmark saved.');}catch(e){status(e.message,true);}});
  root.querySelector('[data-audio-finish]')?.addEventListener('click',async()=>{completed=!completed;finishLabel();if(!completed){audio.currentTime=0;update();}if(await save(true))status(completed?'Marked finished.':'Started again from the beginning.');});
  window.addEventListener('pagehide',()=>{save(true);audio.pause();});
}
