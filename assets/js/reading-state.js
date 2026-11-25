/* Account-scoped progress and bookmarks; failures are visible and safe to retry. */
export function readingState(root, medium, status) {
  const bookId=root.dataset.bookId, api=root.dataset.api;
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  let pending=null, timer=null, inFlight=null, hadError=false,version=root.dataset.progressVersion||'',warning;
  const saveWarning=message=>{
    if(!warning){warning=document.createElement('p');warning.className='alert alert--error';warning.setAttribute('role','alert');root.prepend(warning);}
    warning.textContent=message;warning.hidden=!message;
  };
  const fetchJson=async(url,options={})=>{
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
    try{
      const response=await fetch(url,{...options,signal:controller.signal});
      let result;try{result=await response.json();}catch{const error=Error('The library did not return a valid response. Please try again.');error.permanent=[400,401,403,404,409,413,419,422].includes(response.status);throw error;}
      if(!response.ok||!result.ok){const error=Error(result.error||'Your reading position could not be saved.');error.permanent=[400,401,403,404,409,413,419,422].includes(response.status);throw error;}
      return result;
    }catch(error){if(error.name==='AbortError')throw Error('The library took too long to respond.');throw error;}
    finally{clearTimeout(timeout);}
  };
  const request=async data=>{
    const form=new URLSearchParams({book_id:bookId,medium,revision:root.dataset.revision||'',progress_version:version,...data});
    return fetchJson(api,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':csrf,'Accept':'application/json'},body:form,keepalive:true});
  };
  const flush=()=>{
    clearTimeout(timer);
    if(inFlight)return inFlight;
    if(!pending)return Promise.resolve(true);
    inFlight=(async()=>{
      while(pending){
        const data=pending;pending=null;
        try{const result=await request({action:'progress',...data});version=result.progress_version||version;if(hadError){saveWarning('');status('Your reading position is saved.');hadError=false;}}
        catch(error){
          hadError=true;const message='Not saved: '+error.message+(error.permanent?' Refresh the page before continuing.':' Your latest place will be retried.');saveWarning(message);status(message,true);
          if(error.permanent){pending=null;root.dataset.saveBlocked='1';return false;}
          // If a newer position arrived during this request, discard the older failure.
          if(pending)continue;
          pending=data;clearTimeout(timer);timer=setTimeout(flush,10000);return false;
        }
      }
      return true;
    })().finally(()=>{inFlight=null;});
    return inFlight;
  };
  window.addEventListener('pagehide',()=>{
    if(!pending||inFlight||root.dataset.saveBlocked==='1') return;
    const form=new FormData(); Object.entries({book_id:bookId,medium,revision:root.dataset.revision||'',progress_version:version,_token:csrf,action:'progress',...pending}).forEach(([k,v])=>form.append(k,v));
    navigator.sendBeacon(api,form);
  });
  window.addEventListener('beforeunload',event=>{if(pending||inFlight||hadError){event.preventDefault();event.returnValue='';}});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden') flush();});
  window.addEventListener('online',flush);
  return {
    load:async()=>{
      try{const result=await fetchJson(api+'?'+new URLSearchParams({book_id:bookId,medium}),{headers:{Accept:'application/json'}});
        if(result.revision&&root.dataset.revision&&result.revision!==root.dataset.revision)throw Error('This book file changed. Refresh before continuing.');
        version=result.progress_version||version;return result;
      }catch(error){root.dataset.saveBlocked='1';hadError=true;saveWarning('Your saved place could not load. Progress saving is paused. Refresh before continuing.');throw error;}
    },
    save:(data,immediate=false)=>{if(root.dataset.saveBlocked==='1')return Promise.resolve(false);pending={...data};clearTimeout(timer);if(immediate)return flush();timer=setTimeout(flush,600);},
    flush,
    bookmark:async(position,label)=>request({action:'bookmark',position:Math.floor(position),label}),
    remove:async(id)=>request({action:'remove_bookmark',bookmark_id:id})
  };
}
export function drawBookmarks(list,rows,jump,remove) {
  const focused=document.activeElement,oldRows=[...list.children],focusedIndex=list.contains(focused)?oldRows.findIndex(row=>row.contains(focused)):-1;
  list.replaceChildren();
  if(!rows.length){const li=document.createElement('li');li.className='muted';li.textContent='Keep a passage close. Add your first bookmark.';list.append(li);if(focusedIndex>=0){list.tabIndex=-1;list.focus();}return;}
  for(const row of rows){const li=document.createElement('li'),open=document.createElement('button'),del=document.createElement('button');open.type=del.type='button';open.textContent=row.label;open.addEventListener('click',()=>jump(row.position));del.textContent='×';del.setAttribute('aria-label','Remove bookmark '+row.label);del.addEventListener('click',()=>remove(row.id));li.append(open,del);list.append(li);}
  if(focusedIndex>=0)list.children[Math.min(focusedIndex,rows.length-1)].querySelector('button').focus();
}
