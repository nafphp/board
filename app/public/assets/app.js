
const root=document.documentElement;
const mobileViewport=matchMedia('(max-width:760px)');
function syncSidebar(){const sidebar=document.querySelector('#sidebar');if(sidebar)sidebar.inert=mobileViewport.matches&&!sidebar.classList.contains('open');}
mobileViewport.addEventListener('change',syncSidebar);syncSidebar();
const savedTheme=localStorage.getItem('nafinity.theme');
if(['dark','light','system'].includes(savedTheme))root.dataset.theme=savedTheme;
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
let toastTimer;
function toast(message){const node=document.querySelector('#toast');if(!node)return;node.textContent=message;node.classList.add('visible');clearTimeout(toastTimer);toastTimer=setTimeout(()=>node.classList.remove('visible'),6000);}
function showError(form,message,errors={},conflict=false){let box=form.querySelector('.form-errors');if(!box){box=document.createElement('div');box.className='form-errors';form.prepend(box);}box.replaceChildren();const text=document.createElement('div');text.textContent=message;box.append(text);if(Object.keys(errors).length){const list=document.createElement('ul');for(const [field,messages] of Object.entries(errors)){for(const message of messages){const item=document.createElement('li');item.textContent=message;list.append(item);}const input=form.elements.namedItem(field);if(input instanceof HTMLElement)input.setAttribute('aria-invalid','true');}box.append(list);}if(conflict){const reload=document.createElement('a');reload.href=location.href;reload.textContent='Aktuellen Stand laden';box.append(reload);}box.classList.add('visible');box.scrollIntoView({block:'nearest',behavior:'smooth'});}
document.addEventListener('click',event=>{
 const target=event.target.closest('button,a');if(!target)return;
 if(target.matches('.theme-toggle')){const dark=root.dataset.theme==='dark'||(root.dataset.theme==='system'&&matchMedia('(prefers-color-scheme:dark)').matches);root.dataset.theme=dark?'light':'dark';localStorage.setItem('nafinity.theme',root.dataset.theme);}
 if(target.matches('.mobile-menu')){const sidebar=document.querySelector('#sidebar');sidebar?.classList.toggle('open');target.setAttribute('aria-expanded',sidebar?.classList.contains('open')?'true':'false');syncSidebar();if(sidebar?.classList.contains('open'))sidebar.querySelector('a')?.focus();}
 if(target.dataset.dialog){document.getElementById(target.dataset.dialog)?.showModal();}
 if(target.hasAttribute('data-close-dialog'))target.closest('dialog')?.close();
 if(target.hasAttribute('data-reload'))location.reload();
 if(target.dataset.demo){const form=document.querySelector('.login-card form');form.elements.email.value=target.dataset.demo;form.elements.password.value='Nafinity-Demo-2026!';form.querySelector('button').focus();}
 if(target.hasAttribute('data-move-card')){const card=target.closest('.ticket-card');const cell=card.closest('.board-cell');const board=card.closest('#board');const dialog=document.querySelector('#move-card');const form=dialog.querySelector('form');form.action=`/projects/${board.dataset.project}/tickets/${card.dataset.ticket}/move`;form.elements.version.value=card.dataset.version;form.elements.column_id.value=cell.dataset.column;form.elements.swimlane_id.value=cell.dataset.lane;dialog.querySelector('.move-title').textContent=card.dataset.title;dialog.showModal();}
});
document.addEventListener('submit',async event=>{
 const form=event.target;if(!form.matches('form[data-enhanced]'))return;
 event.preventDefault();const submitter=event.submitter;const button=submitter||form.querySelector('button[type=submit],button:not([type])');
 const data=new FormData(form);if(submitter?.name)data.append(submitter.name,submitter.value);
 if(button)button.disabled=true;
 try{const response=await fetch(form.action,{method:(form.method||'POST').toUpperCase(),body:data,headers:{Accept:'application/json'}});const result=await response.json().catch(()=>({message:response.status===401?'Bitte melde dich erneut an.':'Die Anfrage konnte nicht verarbeitet werden. Bitte lade die Seite neu.'}));
 if(!response.ok){showError(form,result.message||'Die Änderung konnte nicht gespeichert werden.',result.errors||{},response.status===409);return;}
 if(form.action.endsWith('/preferences')){localStorage.setItem('nafinity.theme',data.get('theme'));}
 if(result.url)location.assign(result.url);else location.reload();
 }catch{showError(form,'Die Verbindung ist unterbrochen. Deine Eingaben bleiben erhalten.');}finally{if(button)button.disabled=false;}
});
const board=document.querySelector('#board');let dragged=null;
if(board&&board.dataset.filtered==='0'){
 board.addEventListener('dragstart',event=>{const card=event.target.closest('.ticket-card[draggable=true]');if(!card)return;dragged=card;card.classList.add('dragging');event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',card.dataset.ticket);});
 board.addEventListener('dragend',()=>{dragged?.classList.remove('dragging');dragged=null;board.querySelectorAll('.drag-over').forEach(node=>node.classList.remove('drag-over'));});
 board.addEventListener('dragover',event=>{if(!dragged)return;const cell=event.target.closest('.board-cell');if(!cell)return;event.preventDefault();event.dataTransfer.dropEffect='move';board.querySelectorAll('.drag-over').forEach(node=>node.classList.toggle('drag-over',node===cell));});
 board.addEventListener('drop',async event=>{if(!dragged)return;const cell=event.target.closest('.board-cell');if(!cell)return;event.preventDefault();const card=dragged;const siblings=[...cell.querySelectorAll('.ticket-card')].filter(node=>node!==card);const next=siblings.find(node=>event.clientY<node.getBoundingClientRect().top+node.offsetHeight/2);const index=next?siblings.indexOf(next):siblings.length;const previous=index>0?siblings[index-1]:null;
 const body={version:card.dataset.version,board_revision:board.dataset.revision,column_id:cell.dataset.column,swimlane_id:cell.dataset.lane,placement:'between',left_id:previous?.dataset.ticket||null,right_id:next?.dataset.ticket||null};
 try{const response=await fetch(`/projects/${board.dataset.project}/tickets/${card.dataset.ticket}/move`,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-Token':csrf()},body:JSON.stringify(body)});const result=await response.json();if(!response.ok){toast(result.message||'Verschieben fehlgeschlagen.');document.querySelector('#board-update').hidden=false;return;}location.reload();}catch{toast('Die Verbindung ist unterbrochen. Bitte lade das Board neu.');}});
}
if(board){setInterval(async()=>{if(document.hidden)return;try{const response=await fetch(`/projects/${board.dataset.project}/state`,{headers:{Accept:'application/json'}});if(!response.ok)return;const data=await response.json();if(String(data.revision)!==board.dataset.revision)document.querySelector('#board-update').hidden=false;}catch{}},30000);}
const drawer=document.querySelector('#ticket-drawer');let drawerAbort=null;let drawerCloseFromHistory=false;
document.addEventListener('click',async event=>{
 const link=event.target.closest('a[data-ticket-link]');if(!link||!drawer||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||event.button!==0)return;
 event.preventDefault();drawerAbort?.abort();drawerAbort=new AbortController();drawer.querySelector('.drawer-content').textContent='Ticket wird geladen …';drawer.showModal();
 try{const response=await fetch(link.href+'?fragment=1',{signal:drawerAbort.signal});if(!response.ok){location.assign(link.href);return;}drawer.querySelector('.drawer-content').innerHTML=await response.text();history.pushState({nafinityDrawer:true},'',link.href);drawer.querySelector('button[data-close-drawer]')?.focus();}catch(error){if(error.name!=='AbortError'){drawer.close();toast('Ticket konnte nicht geladen werden.');}}
});
document.addEventListener('click',event=>{if(event.target.closest('[data-close-drawer]')){event.preventDefault();drawer?.close();}});
drawer?.addEventListener('close',()=>{drawerAbort?.abort();if(!drawerCloseFromHistory&&history.state?.nafinityDrawer)history.back();drawerCloseFromHistory=false;});
window.addEventListener('popstate',()=>{if(drawer?.open){drawerCloseFromHistory=true;drawer.close();}});

document.addEventListener('keydown',event=>{if(event.key==='Escape'&&mobileViewport.matches){const sidebar=document.querySelector('#sidebar');if(sidebar?.classList.contains('open')){sidebar.classList.remove('open');syncSidebar();const toggle=document.querySelector('.mobile-menu');toggle?.setAttribute('aria-expanded','false');toggle?.focus();}}});
