import * as pdfjs from '../vendor/pdfjs/build/pdf.mjs';
import {readingState,drawBookmarks} from './reading-state.js';
const root=document.querySelector('[data-reader]');
if(root){
  pdfjs.GlobalWorkerOptions.workerSrc=new URL('../vendor/pdfjs/build/pdf.worker.mjs',import.meta.url).href;
  const status=(message,error=false)=>{const el=root.querySelector('[data-reader-status]');el.textContent=message;el.classList.toggle('is-error',error);};
  const state=readingState(root,'ebook',status), stage=root.querySelector('[data-reader-stage]'),paper=root.querySelector('[data-reader-paper]'),canvas=root.querySelector('canvas'),layer=root.querySelector('[data-reader-text]'),number=root.querySelector('[data-page-number]');
  const prev=root.querySelector('[data-page-prev]'),next=root.querySelector('[data-page-next]'),zoom=root.querySelector('[data-reader-zoom]'),finish=root.querySelector('[data-reader-finish]');
  let pdf,page=Number(root.dataset.page)||1,completed=root.dataset.completed==='1',serial=0,renderTask,textTask;
  const save=(immediate=false)=>state.save({position:page,total:pdf.numPages,completed:completed?'1':'0',rate:1},immediate);
  const bookmarks=rows=>drawBookmarks(root.querySelector('[data-reading-bookmarks]'),rows,p=>render(p),async id=>{try{const data=await state.remove(id);bookmarks(data.bookmarks);status('Bookmark removed.');}catch(e){status(e.message,true);}});
  async function render(wanted=page,persist=true){
    const run=++serial;page=Math.max(1,Math.min(pdf.numPages,Math.floor(wanted)||1));
    if(renderTask){renderTask.cancel();await renderTask.promise.catch(()=>{});if(run!==serial)return;}if(textTask)textTask.cancel();
    prev.disabled=page===1;next.disabled=page===pdf.numPages;number.value=page;number.max=pdf.numPages;
    root.querySelector('[data-page-total]').textContent='of '+pdf.numPages;
    status('Page '+page+' of '+pdf.numPages+(completed?' · Finished':''));
    try{
      const pdfPage=await pdf.getPage(page);if(run!==serial)return;
      const base=pdfPage.getViewport({scale:1}),scale=zoom.value==='fit'?Math.max(.2,(stage.clientWidth-32)/base.width):Number(zoom.value),viewport=pdfPage.getViewport({scale}),density=Math.min(devicePixelRatio||1,2);
      paper.style.width=viewport.width+'px';paper.style.height=viewport.height+'px';paper.style.setProperty('--scale-factor',scale);paper.style.setProperty('--user-unit',viewport.userUnit||1);paper.style.setProperty('--total-scale-factor',scale);
      canvas.width=Math.floor(viewport.width*density);canvas.height=Math.floor(viewport.height*density);canvas.style.width=viewport.width+'px';canvas.style.height=viewport.height+'px';layer.replaceChildren();
      renderTask=pdfPage.render({canvasContext:canvas.getContext('2d'),viewport,transform:density===1?null:[density,0,0,density,0,0]});await renderTask.promise;if(run!==serial)return;
      textTask=new pdfjs.TextLayer({textContentSource:await pdfPage.getTextContent(),container:layer,viewport});await textTask.render();
      paper.setAttribute('aria-label','Page '+page);if(persist)save();
    }catch(error){if(error.name!=='RenderingCancelledException'&&error.name!=='AbortException'&&run===serial)status('This page could not be displayed. Try another page or open the original PDF.',true);}
  }
  try{
    let loaded=true;
    try{const remembered=await state.load();if(remembered.progress){page=Number(remembered.progress.position)||page;completed=Number(remembered.progress.completed)===1;}bookmarks(remembered.bookmarks);}
    catch{loaded=false;root.querySelector('[data-reading-bookmarks]').textContent='Your bookmarks could not load. Refresh to try again.';}
    pdf=await pdfjs.getDocument({url:root.dataset.pdf,isEvalSupported:false,cMapUrl:new URL('../vendor/pdfjs/cmaps/',import.meta.url).href,cMapPacked:true,standardFontDataUrl:new URL('../vendor/pdfjs/standard_fonts/',import.meta.url).href,wasmUrl:new URL('../vendor/pdfjs/wasm/',import.meta.url).href,useWasm:false}).promise;
    root.querySelectorAll('button,input,select').forEach(el=>el.disabled=false);
    finish.textContent=completed?'Read again':'Mark finished';await render(page,loaded);
    if(!loaded){const warning=root.querySelector('[data-reader-warning]');warning.hidden=false;warning.textContent='Your saved place and bookmarks could not be refreshed. You can keep reading from your last known page. Refresh to try again.';}
    prev.addEventListener('click',()=>render(page-1));next.addEventListener('click',()=>render(page+1));number.addEventListener('change',()=>render(Number(number.value)));zoom.addEventListener('change',()=>render(page,false));
    root.querySelectorAll('[data-reader-jump]').forEach(b=>b.addEventListener('click',()=>render(Number(b.dataset.readerJump))));
    root.querySelectorAll('[data-reader-jump]').forEach(b=>{if(Number(b.dataset.readerJump)>pdf.numPages){b.disabled=true;b.title='This chapter is outside the current PDF.';}});
    stage.addEventListener('keydown',e=>{if(window.getSelection()?.toString())return;if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();render(page+(e.key==='ArrowRight'?1:-1));}});
    root.querySelector('[data-reader-bookmark]').addEventListener('click',async()=>{try{const data=await state.bookmark(page,'Page '+page);bookmarks(data.bookmarks);status('Bookmarked page '+page+'.');}catch(e){status(e.message,true);}});
    finish.addEventListener('click',async()=>{completed=!completed;finish.textContent=completed?'Read again':'Mark finished';if(!completed)await render(1,false);if(await save(true))status(completed?'Marked finished. Your last page is kept.':'Started again from page 1.');});
    let resizeTimer;new ResizeObserver(()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{if(zoom.value==='fit')render(page,false);},180);}).observe(stage);
  }catch(error){status('The book could not load. Open the original PDF above, or try again later.',true);}
}
