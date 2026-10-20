const {JSDOM,VirtualConsole}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),wait=()=>new Promise(r=>setTimeout(r,25));
(async()=>{
const fixture=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/student-record.json'),'utf8')),dom=new JSDOM('<main data-erp-screen="alunos"></main>',{runScripts:'dangerously',url:'https://example.test/portal?aluno=1&aba=responsaveis',virtualConsole:new VirtualConsole()}),w=dom.window,d=w.document,calls=[];
w.EDERP=fixture.settings;w.EDERP.caps.chat=w.EDERP.caps.sendGuardianEmail=true;w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
w.fetch=async(raw,opt={})=>{const route=new URL(raw).pathname.slice(5),body=opt.body&&JSON.parse(opt.body);calls.push({route,body});let result=fixture.sheet;
if(route.endsWith('/email'))result=body?{enviado:true,mensagem:'E-mail encaminhado para envio pelo WordPress.'}:{remetente:{nome:'Secretaria Teste',email:'secretaria@example.test'},destinatario:{nome:'CAMILA HELENA DA SILVA',email:'camila@example.test'},aluno:{nome:'CARLOS EDUARDO DA SILVA',ra:'123456'},versao:'v1'};
if(route==='chat/contato')result={items:[{idcanal:5,nome:'Secretaria'},{idcanal:6,nome:'Coordenação'}]};
if(route==='chat/conversas')result={idconversa:99};
return {ok:true,json:async()=>result};};
for(const f of ['action-menu.js','modals.js','person-fields.js','workflow.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets',f),'utf8'));await wait();
const card=d.querySelector('.erp-guardian-card'),link=card.querySelector('a[href="#enviar-email"]');assert.ok(link);link.click();await wait();let modal=d.querySelector('dialog[open]'),form=modal.querySelector('form');assert.match(modal.textContent,/secretaria@example.test/);assert.match(modal.textContent,/camila@example.test/);
form.querySelector('[name=assunto]').value='Assunto pessoal';form.querySelector('[name=corpo]').value='Olá, família.\nGostaria de conversar.';form.requestSubmit();await wait();
const posted=calls.find(c=>c.route.endsWith('/email')&&c.body);assert.deepEqual(posted.body,{assunto:'Assunto pessoal',corpo:'Olá, família.\nGostaria de conversar.',versao:'v1'});assert.equal(form.querySelector('[name=corpo]').disabled,true);assert.match(modal.textContent,/encaminhado para envio/);modal.querySelector('.erp-contact-header button').click();
[...card.querySelectorAll('button')].find(b=>b.textContent==='Conversar').click();await wait();modal=d.querySelector('dialog[open]');form=modal.querySelector('form');assert.equal(form.querySelectorAll('select').length,1);assert.deepEqual([...form.querySelector('select').options].map(o=>o.textContent),['Selecione...','Secretaria','Coordenação']);
form.querySelector('select').value='6';form.requestSubmit();await wait();assert.deepEqual(calls.find(c=>c.route==='chat/conversas').body,{idcanal:'6',idaluno:1,codpessoa:2});
console.log('PASS: e-mail abre modal com remetente/contato fixos e conversa mantém aluno/responsável ao escolher canal.');w.close();
})().catch(e=>{console.error(e);process.exit(1)});
