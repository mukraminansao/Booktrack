(() => {
  const dialog=document.querySelector('[data-listening-search-dialog]');if(!dialog)return;
  const input=dialog.querySelector('input'),rows=[...dialog.querySelectorAll('[data-listening-title]')],empty=dialog.querySelector('[data-listening-empty]');
  const normalize=text=>text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  input.addEventListener('input',()=>{
    const query=normalize(input.value.trim());let count=0;
    rows.forEach(row=>{row.hidden=!normalize(row.textContent).includes(query);if(!row.hidden)count++;});
    empty.hidden=count!==0;
  });
})();
