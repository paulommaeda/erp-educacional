(()=>{'use strict';
let serial=0;
function mount(nav){
 const buttons=[...nav.querySelectorAll('button[data-tab]')];if(!buttons.length)return;
 nav.classList.add('erp-responsive-tabs');nav.setAttribute('aria-label','Áreas da ficha do aluno');
 const wrap=document.createElement('div'),toggle=document.createElement('button'),menu=document.createElement('div');wrap.className='erp-tab-overflow';toggle.type='button';toggle.className='erp-secondary erp-tab-menu-toggle';toggle.textContent='☰';toggle.setAttribute('aria-label','Mais áreas da ficha do aluno');toggle.setAttribute('aria-expanded','false');menu.id='erp-tab-menu-'+(++serial);toggle.setAttribute('aria-controls',menu.id);menu.className='erp-tab-menu';menu.hidden=true;wrap.append(toggle,menu);nav.append(wrap);
 let frame=0,lastWidth=-1,closed=false;
 const close=()=>{menu.hidden=true;toggle.setAttribute('aria-expanded','false');};
 function sync(){const selected=[...menu.children].some(b=>b.classList.contains('active'));if(toggle.classList.contains('active')!==selected)toggle.classList.toggle('active',selected);}
 function layout(){frame=0;if(closed||!nav.isConnected)return;const width=nav.getBoundingClientRect().width;if(width<=0)return;close();for(const b of buttons)nav.insertBefore(b,wrap);wrap.hidden=false;
 const gap=parseFloat(getComputedStyle(nav).columnGap)||8, widths=buttons.map(b=>b.getBoundingClientRect().width),total=widths.reduce((a,b)=>a+b,0)+gap*(buttons.length-1);
 if(total<=width){wrap.hidden=true;sync();return;}
 const available=Math.max(0,width-toggle.getBoundingClientRect().width-gap);let used=0,count=0;for(const w of widths){const next=used+w+(count?gap:0);if(next>available)break;used=next;count++;}
 for(const b of buttons.slice(count))menu.append(b);sync();
 }
 function schedule(){if(!frame&&!closed)frame=requestAnimationFrame(layout);}
 toggle.addEventListener('click',()=>{const open=menu.hidden;menu.hidden=!open;toggle.setAttribute('aria-expanded',String(open));if(open)menu.querySelector('button')?.focus();});
 function click(e){if(!nav.isConnected){destroy();return;}if(!wrap.contains(e.target))close();if(menu.contains(e.target)&&e.target.closest('button[data-tab]')){close();sync();toggle.focus();}}
 function keys(e){if(e.key==='Escape'&&!menu.hidden){close();toggle.focus();}if(!menu.hidden&&menu.contains(e.target)&&['ArrowDown','ArrowUp','Home','End'].includes(e.key)){e.preventDefault();const items=[...menu.children],i=items.indexOf(document.activeElement);items[e.key==='Home'?0:e.key==='End'?items.length-1:(i+(e.key==='ArrowDown'?1:-1)+items.length)%items.length]?.focus();}}
 document.addEventListener('click',click);nav.addEventListener('keydown',keys);window.addEventListener('resize',schedule);
 const resize=typeof ResizeObserver==='function'?new ResizeObserver(entries=>{const width=entries[0].contentRect.width;if(width!==lastWidth){lastWidth=width;schedule();}}):null;resize?.observe(nav);
 const active=new MutationObserver(sync);active.observe(nav,{subtree:true,attributes:true,attributeFilter:['class']});
 const removal=new MutationObserver(()=>{if(!nav.isConnected)destroy();});removal.observe(nav.parentElement,{childList:true});
 function destroy(){if(closed)return;closed=true;if(frame)cancelAnimationFrame(frame);resize?.disconnect();active.disconnect();removal.disconnect();document.removeEventListener('click',click);nav.removeEventListener('keydown',keys);window.removeEventListener('resize',schedule);}
 document.fonts?.ready.then(schedule);schedule();return {refresh:schedule,destroy};
}
window.EDERPResponsiveTabs={mount};})();
