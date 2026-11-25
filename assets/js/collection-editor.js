document.querySelectorAll('[data-collection-picker]').forEach(picker=>{
  for(const choice of picker.querySelectorAll('.collection-choice')){
    const checkbox=choice.querySelector('[type=checkbox]'),order=choice.querySelector('[type=number]');
    const update=()=>{order.disabled=!checkbox.checked;order.parentElement.hidden=!checkbox.checked;};
    checkbox.addEventListener('change',update);update();
  }
});
