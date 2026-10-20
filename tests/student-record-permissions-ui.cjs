const {JSDOM}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),wait=()=>new Promise(r=>setTimeout(r,25));
(async()=>{
const dom=new JSDOM('<div class="journey-app ederp"><div class="journey-bottom"></div><dialog><button data-close-menu>Fechar</button></dialog><main data-journey-view="perfis"></main></div>',{runScripts:'dangerously',url:'https://example.test/portal'}),w=dom.window,d=w.document,calls=[];
w.JOURNEY={url:'https://example.test/portal',view:'perfis',menus:{inicio:'Início'}};w.EDERP={root:'https://example.test/api/'};
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
w.fetch=async(raw,opt={})=>{const route=new URL(raw).pathname.slice(5),body=opt.body&&JSON.parse(opt.body);calls.push({route,body});return {ok:true,json:async()=>body?{salvo:true}:route==='perfis'?{roles:[{slug:'erp_secretaria',nome:'Secretaria',menus:['alunos'],student_sections:['ficha_medica']}],menus:{alunos:'Alunos'},student_sections:{ficha_medica:'Ficha médica',dificuldades_aprendizagem:'Dificuldades de aprendizagem',laudos:'Laudos'}}:{items:[],more:false}};};
for(const f of ['modals.js','portal.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets',f),'utf8'));await wait();
[...d.querySelectorAll('.erp-modal-trigger')].find(b=>b.textContent==='Editar acessos: Secretaria').click();
const form=d.querySelector('dialog[open] form');assert.equal(form.querySelector('[name=student_section][value=ficha_medica]').checked,true);form.querySelector('[value=ficha_medica]').checked=false;form.querySelector('[value=laudos]').checked=true;form.requestSubmit();await wait();
assert.deepEqual(calls.find(c=>c.body).body.student_sections,['laudos']);assert.deepEqual(calls.find(c=>c.body).body.menus,['alunos']);
console.log('PASS: modal de perfis permite conceder/revogar áreas protegidas sem mudar menus.');w.close();
})().catch(e=>{console.error(e);process.exit(1)});
