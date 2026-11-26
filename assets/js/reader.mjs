import * as pdfjs from '../vendor/pdfjs/build/pdf.mjs';
import {readingState,drawBookmarks} from './reading-state.js';
const root=document.querySelector('[data-reader]');
if(root){
  pdfjs.GlobalWorkerOptions.workerSrc=new URL('../vendor/pdfjs/build/pdf.worker.mjs',import.meta.url).href;
  const status=(message,error=false)=>{const el=root.querySelector('[data-reader-status]');el.textContent=message;el.classList.toggle('is-error',error);};
  const state=readingState(root,'ebook',status), stage=root.querySelector('[data-reader-stage]'),paper=root.querySelector('[data-reader-paper]'),canvas=root.querySelector('canvas'),layer=root.querySelector('[data-reader-text]'),number=root.querySelector('[data-page-number]');
  const prev=[...root.querySelectorAll('[data-page-prev]')],next=[...root.querySelectorAll('[data-page-next]')],zoom=root.querySelector('[data-reader-zoom]'),finish=root.querySelector('[data-reader-finish]');
  let pdf,page=Number(root.dataset.page)||1,completed=root.dataset.completed==='1',serial=0,renderTask,textTask;
  const save=(immediate=false)=>state.save({position:page,total:pdf.numPages,completed:completed?'1':'0',rate:1},immediate);
  const bookmarks=rows=>drawBookmarks(root.querySelector('[data-reading-bookmarks]'),rows,p=>render(p,true,true),async id=>{try{const data=await state.remove(id);bookmarks(data.bookmarks);status('Bookmark removed.');}catch(e){status(e.message,true);}});
  async function render(wanted=page,persist=true,align=false){
    const run=++serial;page=Math.max(1,Math.min(pdf.numPages,Math.floor(wanted)||1));
    if(renderTask){renderTask.cancel();await renderTask.promise.catch(()=>{});if(run!==serial)return;}if(textTask)textTask.cancel();
    prev.forEach(b=>b.disabled=page===1);next.forEach(b=>b.disabled=page===pdf.numPages);number.value=page;number.max=pdf.numPages;
    root.querySelector('[data-page-total]').textContent='of '+pdf.numPages;
    root.querySelector('[data-reader-foot-page]').textContent=page+' / '+pdf.numPages;
    status('Page '+page+' of '+pdf.numPages+(completed?' · Finished':''));
    try{
      const pdfPage=await pdf.getPage(page);if(run!==serial)return;
      const base=pdfPage.getViewport({scale:1}),style=getComputedStyle(stage);
      const horizontal=parseFloat(style.paddingLeft)+parseFloat(style.paddingRight),vertical=parseFloat(style.paddingTop)+parseFloat(style.paddingBottom);
      const widthScale=Math.max(.05,(stage.clientWidth-horizontal)/base.width),heightScale=Math.max(.05,(parseFloat(style.maxHeight)-vertical-2)/base.height);
      const scale=zoom.value==='page'?Math.min(widthScale,heightScale):zoom.value==='fit'?widthScale:Number(zoom.value),viewport=pdfPage.getViewport({scale}),density=Math.min(devicePixelRatio||1,2);
      paper.style.width=viewport.width+'px';paper.style.height=viewport.height+'px';paper.style.setProperty('--scale-factor',scale);paper.style.setProperty('--user-unit',viewport.userUnit||1);paper.style.setProperty('--total-scale-factor',scale);
      canvas.width=Math.floor(viewport.width*density);canvas.height=Math.floor(viewport.height*density);canvas.style.width=viewport.width+'px';canvas.style.height=viewport.height+'px';layer.replaceChildren();
      renderTask=pdfPage.render({canvasContext:canvas.getContext('2d'),viewport,transform:density===1?null:[density,0,0,density,0,0]});await renderTask.promise;if(run!==serial)return;
      textTask=new pdfjs.TextLayer({textContentSource:await pdfPage.getTextContent(),container:layer,viewport});await textTask.render();
      paper.setAttribute('aria-label','Page '+page);
      if(align){stage.scrollTop=0;stage.scrollLeft=0;stage.scrollIntoView({block:'start',behavior:'instant'});}
      if(persist)save();
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
    prev.forEach(b=>b.addEventListener('click',()=>render(page-1,true,true)));next.forEach(b=>b.addEventListener('click',()=>render(page+1,true,true)));number.addEventListener('change',()=>render(Number(number.value),true,true));zoom.addEventListener('change',()=>{stage.scrollTop=0;stage.scrollLeft=0;render(page,false);});
    root.querySelectorAll('[data-reader-jump]').forEach(b=>b.addEventListener('click',()=>render(Number(b.dataset.readerJump),true,true)));
    root.querySelectorAll('[data-reader-jump]').forEach(b=>{if(Number(b.dataset.readerJump)>pdf.numPages){b.disabled=true;b.title='This chapter is outside the current PDF.';}});
    stage.addEventListener('keydown',e=>{if(window.getSelection()?.toString())return;if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();render(page+(e.key==='ArrowRight'?1:-1),true,true);}});
    root.querySelector('[data-reader-bookmark]').addEventListener('click',async()=>{try{const data=await state.bookmark(page,'Page '+page);bookmarks(data.bookmarks);status('Bookmarked page '+page+'.');}catch(e){status(e.message,true);}});
    finish.addEventListener('click',async()=>{completed=!completed;finish.textContent=completed?'Read again':'Mark finished';if(!completed)await render(1,false,true);if(await save(true))status(completed?'Marked finished. Your last page is kept.':'Started again from page 1.');});
    let resizeTimer,lastWidth=stage.clientWidth,lastMaxHeight=getComputedStyle(stage).maxHeight;
    const resize=()=>{const width=stage.clientWidth,maxHeight=getComputedStyle(stage).maxHeight;if(width===lastWidth&&maxHeight===lastMaxHeight)return;lastWidth=width;lastMaxHeight=maxHeight;clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{if(zoom.value==='fit'||zoom.value==='page')render(page,false);},180);};
    new ResizeObserver(resize).observe(stage);window.addEventListener('resize',resize);
  }catch(error){status('The book could not load. Open the original PDF above, or try again later.',true);}
}
