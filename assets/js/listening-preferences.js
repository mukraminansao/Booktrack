/* Browser-only listening preferences, scoped to the signed-in account. */
const bounded=(value,min,max,fallback)=>typeof value==='number'&&Number.isFinite(value)&&value>=min&&value<=max?value:fallback;
const validRate=value=>[.5,.75,.95,1,1.25,1.5,1.75,2].includes(value)?value:.95;
export function listeningStore(storage,memberId,appBase=''){
  const prefix='booktrack.listening.v1.'+(appBase?encodeURIComponent(appBase)+'.':'')+memberId;
  const read=key=>{try{return JSON.parse(storage?.getItem(key)||'null');}catch{return null;}};
  const write=(key,value)=>{try{storage?.setItem(key,JSON.stringify(value));}catch{/* Playback must work with storage blocked or full. */}};
  const settings=()=>{const value=read(prefix+'.settings')||{};return {rate:validRate(value.rate),volume:bounded(value.volume,0,1,1)};};
  return {
    settings,
    saveSettings(patch){const value={...settings(),...patch};write(prefix+'.settings',{rate:validRate(value.rate),volume:bounded(value.volume,0,1,1)});},
    load(bookId,revision){
      const value=read(prefix+'.previews')?.['book-'+bookId];if(!value||value.revision!==revision)return null;
      return {chapter:Math.floor(bounded(value.chapter,0,100,0)),position:bounded(value.position,0,100,0),elapsed:bounded(value.elapsed,0,86400000,0)};
    },
    save(bookId,revision,value){
      const raw=read(prefix+'.previews');const rows=raw&&typeof raw==='object'&&!Array.isArray(raw)?raw:{};
      const id='book-'+bookId;delete rows[id];rows[id]={revision,chapter:Math.floor(bounded(value.chapter,0,100,0)),position:bounded(value.position,0,100,0),elapsed:bounded(value.elapsed,0,86400000,0)};
      write(prefix+'.previews',Object.fromEntries(Object.entries(rows).slice(-20)));
    }
  };
}

export function rememberListeningBook(bookId,memberId,path){
  if(!/^\d+$/.test(String(bookId))||!/^\d+$/.test(String(memberId)))return;
  document.cookie='bt_listening_'+memberId+'='+bookId+'; path='+path+'; max-age=2592000; samesite=lax'+(location.protocol==='https:'?'; secure':'');
}
