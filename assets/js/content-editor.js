import * as pdfjs from '../vendor/pdfjs/build/pdf.mjs';
pdfjs.GlobalWorkerOptions.workerSrc=new URL('../vendor/pdfjs/build/pdf.worker.mjs',import.meta.url).href;
const editor=document.querySelector('[data-chapter-editor]');
if(editor){
  let checked=false;
  const duration=source=>new Promise((resolve,reject)=>{
    const audio=new Audio(),timer=setTimeout(()=>done(Error('The recording length could not be verified. Try a supported recording or reload.')),20000);
    const done=(error)=>{clearTimeout(timer);audio.onloadedmetadata=audio.onerror=null;const total=audio.duration;audio.removeAttribute('src');audio.load();error?reject(error):resolve(total);};
    audio.preload='metadata';audio.onloadedmetadata=()=>Number.isFinite(audio.duration)&&audio.duration>0?done():done(Error('This recording has no readable duration.'));audio.onerror=()=>done(Error('The recording could not be opened.'));audio.src=source;
  });
  editor.addEventListener('submit',async event=>{
    if(checked)return;event.preventDefault();const button=event.submitter||editor.querySelector('button[type=submit]'),message=editor.querySelector('[data-media-metadata]');button.disabled=true;
    let objectUrl;
    try{
      message.textContent='Checking PDF pages and recording duration…';
      if(editor.dataset.pdf){const pdf=await pdfjs.getDocument({url:editor.dataset.pdf,isEvalSupported:false,useWasm:false}).promise;editor.elements.ebook_total.value=pdf.numPages;await pdf.destroy();}
      const file=editor.elements.audio_file.files[0],removed=editor.elements.remove_audio?.checked;
      const source=file?(objectUrl=URL.createObjectURL(file)):removed?'':editor.dataset.audio;
      editor.elements.audio_total.value=source?await duration(source):'';
      for(const medium of ['ebook','audio']){
        if(medium==='audio'&&removed&&!file)continue;
        const total=Number(editor.elements[medium+'_total'].value);
        for(const input of editor.querySelectorAll('[name="'+medium+'_position[]"]')){
          if(!input.value.trim())continue;const parts=input.value.trim().split(':'),position=parts.reduce((sum,part)=>sum*60+Number(part),0);
          if(!total||!Number.isFinite(position)||position>total||(medium==='audio'&&position>=total))throw Error('A '+medium+' chapter is beyond the end of its file. Correct the marker before saving.');
        }
      }
      checked=true;message.textContent='File lengths verified.';button.disabled=false;editor.requestSubmit(button);
    }catch(error){message.textContent=error.message;message.setAttribute('role','alert');button.disabled=false;}
    finally{if(objectUrl)URL.revokeObjectURL(objectUrl);}
  });
}
document.querySelectorAll('[data-chapter-group]').forEach(group=>{
  const list=group.querySelector('[data-chapter-rows]');
  const add=group.querySelector('[data-add-chapter]');
  const refresh=()=>{
    add.disabled=list.children.length>=200;
    [...list.children].forEach((row,index)=>row.querySelectorAll('input').forEach(input=>input.setAttribute('aria-label',group.querySelector('h2').textContent+' '+(input.name.includes('title')?'title ':'position ')+(index+1))));
  };
  add.addEventListener('click',()=>{
    if(list.children.length>=200)return;
    const row=list.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(input=>{input.value='';});
    list.append(row);refresh();row.querySelector('input').focus();
  });
  list.addEventListener('click',event=>{const button=event.target.closest('[data-remove-chapter]');if(!button)return;const row=button.closest('.chapter-edit-row');if(list.children.length>1){const next=row.nextElementSibling||row.previousElementSibling;row.remove();refresh();next.querySelector('input').focus();}else{row.querySelectorAll('input').forEach(input=>input.value='');row.querySelector('input').focus();}});
  refresh();
});
