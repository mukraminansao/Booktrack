/* Preserve the page, filters and scroll position behind detail views. */
(() => {
  const base=document.body.dataset.appBase;
  if(!base)return;
  const details=new Set(['book.php','reader.php','listen.php','reviews.php','collection.php','admin/media.php']);
  const key=url=>url.pathname+url.search;
  const storage='booktrack.navigation.positions';
  const local=url=>url.origin===location.origin&&url.pathname.startsWith(base);
  const current=()=>location.pathname+location.search+location.hash;
  const prepare=link=>{
    if(link.matches('[data-navigation-back],.back-link,[download]'))return;
    const url=new URL(link.href,location.href);
    if(!local(url)||!details.has(url.pathname.slice(base.length)))return;
    const here=new URL(location.href);
    if(url.pathname===here.pathname&&(url.pathname.slice(base.length)!=='book.php'||url.searchParams.get('id')===here.searchParams.get('id'))){
      const origin=here.searchParams.get('return_to');if(origin)url.searchParams.set('return_to',origin);
      link.href=url.href;return;
    }
    const source=current();
    if(source.length<=7000){url.searchParams.set('return_to',source);if(url.href.length<=7800)link.href=url.href;}
  };
  document.querySelectorAll('a[href]').forEach(prepare);
  document.addEventListener('click',event=>{
    const link=event.target.closest('a[href]');if(!link)return;
    prepare(link);
    const destination=new URL(link.href,location.href);if(!local(destination))return;
    try{
      const positions=JSON.parse(sessionStorage.getItem(storage)||'{}');
      const page=key(new URL(location.href));delete positions[page];positions[page]=[scrollX,scrollY];
      const entries=Object.entries(positions).slice(-40);sessionStorage.setItem(storage,JSON.stringify(Object.fromEntries(entries)));
      if(link.hasAttribute('data-navigation-back'))sessionStorage.setItem('booktrack.navigation.restore',key(destination));
    }catch{/* Navigation also works when browser storage is unavailable. */}
  },true);
  try{
    if(sessionStorage.getItem('booktrack.navigation.restore')===key(new URL(location.href))){
      sessionStorage.removeItem('booktrack.navigation.restore');
      const position=JSON.parse(sessionStorage.getItem(storage)||'{}')[key(new URL(location.href))];
      if(Array.isArray(position))window.addEventListener('load',()=>requestAnimationFrame(()=>scrollTo(position[0],position[1])),{once:true});
    }
  }catch{/* Use the normal URL/fragment fallback. */}
})();
