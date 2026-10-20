const {JSDOM}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),wait=()=>new Promise(r=>setTimeout(r,15));
(async()=>{
const dom=new JSDOM('<div class="journey-app ederp"><header class="journey-header"><details class="journey-account-menu"><summary class="journey-account" aria-expanded="false" aria-controls="journey-account-submenu"><img alt="" width="40" height="40"><span class="journey-account-name"></span></summary><nav class="journey-account-submenu" id="journey-account-submenu"><a href="/portal?erp_tela=perfil">Meu perfil</a><a class="journey-account-logout" href="/portal?erp_auth=sair&amp;_wpnonce=test">Sair</a></nav></details></header><main></main><nav class="journey-bottom"></nav><dialog><button data-close-menu>Fechar</button></dialog></div>',{runScripts:'dangerously',url:'https://example.test/portal'}),w=dom.window,d=w.document;
w.JOURNEY={url:'https://example.test/portal',name:'  Maria   da Silva  ',menus:{inicio:'Início'},view:'chat'};w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
w.eval(fs.readFileSync(path.join(__dirname,'../assets/portal.js'),'utf8'));
const account=d.querySelector('.journey-account-menu'),toggle=account.querySelector('summary'),link=account.querySelector('a'),outside=d.querySelector('main');
const pointer=(element,type,pointerType)=>{const e=new w.Event(type,{bubbles:type==='pointerdown'});Object.defineProperty(e,'pointerType',{value:pointerType});element.dispatchEvent(e);};
assert.equal(d.querySelector('.journey-account-name').textContent,'Maria');assert.equal(account.open,false);
pointer(account,'pointerenter','mouse');assert.equal(account.open,true);assert.equal(toggle.getAttribute('aria-expanded'),'true');
pointer(account,'pointerleave','mouse');assert.equal(account.open,false);
pointer(account,'pointerenter','touch');assert.equal(account.open,false);toggle.click();await wait();assert.equal(account.open,true);
pointer(outside,'pointerdown','touch');assert.equal(account.open,false);
toggle.focus();toggle.dispatchEvent(new w.KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true}));assert.equal(account.open,true);assert.equal(d.activeElement,link);
link.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(account.open,false);assert.equal(d.activeElement,toggle);
assert.equal(account.querySelector('.journey-account-logout').href,'https://example.test/portal?erp_auth=sair&_wpnonce=test');
console.log('PASS: primeiro nome, hover, toque, clique fora, teclado e link de saída do portal.');w.close();
})().catch(e=>{console.error(e);process.exit(1)});
