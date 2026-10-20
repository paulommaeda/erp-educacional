const {JSDOM}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),wait=()=>new Promise(r=>setTimeout(r,25));
(async()=>{
const dom=new JSDOM('<section data-erp-chat></section>',{url:'https://example.test/portal',runScripts:'dangerously'}),w=dom.window,d=w.document,calls=[];
w.EDERP={root:'/api/',nonce:'test'};w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.dispatchEvent(new w.Event('close'));};
w.fetch=async(url,opt)=>{const route=url.replace('/api/chat/',''),data=opt.body&&JSON.parse(opt.body);calls.push({route,data});return {ok:true,json:async()=>{
if(route==='canais')return {items:[{idcanal:1,nome:'Secretaria',ativo:1,atendente:true}],admin:false};
if(route.includes('/responsaveis?'))return {items:[{codpessoa:7,nome:'MARIA DA SILVA'}],more:false};
if(route==='canais/1/alunos?codpessoa=7')return {items:[{idaluno:3,nome:'JOAO DA SILVA',ra:'001'},{idaluno:4,nome:'ANA DA SILVA',ra:'002'}]};
if(route==='conversas'&&data)return {idconversa:8};
if(route==='conversas/8')return {idconversa:8,idcanal:1,responsavel:'MARIA DA SILVA',idaluno:4,aluno_nome:'ANA DA SILVA',ra:'002',ativo:1};
return {items:[],more:false};
}}};
w.eval(fs.readFileSync(path.join(__dirname,'../assets/chat.js'),'utf8'));await wait();[...d.querySelectorAll('button')].find(b=>b.textContent==='Nova conversa').click();let form=d.querySelector('dialog form'),select=form.querySelector('select');select.value='1';select.dispatchEvent(new w.Event('change'));await wait();assert.equal(form.querySelectorAll('select').length,2);assert.equal(calls.some(c=>c.route.includes('/alunos')),false);
select=form.querySelectorAll('select')[1];select.value='7';select.dispatchEvent(new w.Event('change'));await wait();assert.equal(form.querySelectorAll('select').length,3);assert.ok(calls.some(c=>c.route==='canais/1/alunos?codpessoa=7'));
form.querySelectorAll('select')[2].value='4';form.requestSubmit();await wait();const posted=calls.find(c=>c.route==='conversas'&&c.data).data;assert.equal(posted.codpessoa,'7');assert.equal(posted.idaluno,'4');assert.equal(d.querySelector('.erp-chat-identity').textContent,'MARIA · ANA · RA 002');
console.log('PASS: escola escolhe responsável e depois aluno antes de iniciar contato.');w.close();
})().catch(e=>{console.error(e);process.exit(1)});
