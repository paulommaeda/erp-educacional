(()=>{
'use strict';
const h=(tag,text,cls)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(cls)node.className=cls;return node;};
  function create(actions,root=document.body,label='Ações do registro'){
    const menu=h('details',undefined,'erp-enrollment-actions'),toggle=h('summary','☰'),items=h('div',undefined,'erp-enrollment-action-items');
    toggle.setAttribute('aria-label',label);toggle.title=label;toggle.setAttribute('aria-expanded','false');
    items.setAttribute('popover','auto');items.append(...actions);menu.append(toggle,items);
    let listeners=null,observer=null;
    const stop=()=>{listeners?.abort();listeners=null;observer?.disconnect();observer=null;};
    const close=()=>{menu.open=false;toggle.setAttribute('aria-expanded','false');if(items.hidePopover&&items.matches(':popover-open'))items.hidePopover();stop();};
    const position=()=>{
      const rect=toggle.getBoundingClientRect(),width=Math.min(280,window.innerWidth-24);
      items.style.width=width+'px';items.style.maxHeight=Math.max(44,window.innerHeight-24)+'px';
      const height=items.getBoundingClientRect().height;
      const below=window.innerHeight-rect.bottom-12,above=rect.top-12;
      const top=below>=height||below>=above?rect.bottom+6:rect.top-height-6;
      items.style.left=Math.max(12,Math.min(rect.right-width,window.innerWidth-width-12))+'px';
      items.style.top=Math.max(12,Math.min(top,window.innerHeight-height-12))+'px';
    };
    menu.addEventListener('toggle',()=>{
      toggle.setAttribute('aria-expanded',String(menu.open));
      if(!menu.open){close();return;}
      root.querySelectorAll('.erp-enrollment-actions[open]').forEach(other=>{if(other!==menu)other.open=false;});
      if(items.showPopover&&!items.matches(':popover-open'))items.showPopover();
      position();stop();listeners=new AbortController();const opts={signal:listeners.signal};
      document.addEventListener('pointerdown',e=>{if(!menu.contains(e.target))close();},opts);
      document.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();toggle.focus();}},opts);
      window.addEventListener('resize',position,opts);
      document.addEventListener('scroll',position,{...opts,capture:true});
      observer=new MutationObserver(()=>{if(!menu.isConnected)close();});observer.observe(root,{childList:true,subtree:true});
    });
    items.addEventListener('toggle',e=>{if(e.newState==='closed'&&menu.open)close();});
    menu.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();toggle.focus();}});
    items.addEventListener('click',e=>{if(e.target.closest('button,a'))close();});
    return menu;
  }
window.EDERPActionMenu={create};
})();
