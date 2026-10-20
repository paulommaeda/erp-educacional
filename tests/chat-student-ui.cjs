const {JSDOM}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const wait=()=>new Promise(r=>setTimeout(r,25));
(async()=>{
const dom=new JSDOM('<section data-erp-chat></section>',{url:'https://example.test/portal',runScripts:'dangerously',pretendToBeVisual:true}),w=dom.window,d=w.document;
w.EDERP={root:'/api/',nonce:'test'};w.crypto.randomUUID=()=> 'test-key';
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
const calls=[],students=[{idaluno:3,nome:'JOAO DA SILVA',ra:'001'},{idaluno:4,nome:'ANA DA SILVA',ra:'002'}];
const conversations=[{idconversa:9,idcanal:1,codpessoa:1,canal:'Secretaria',responsavel:'MARIA DA SILVA',idaluno:3,aluno_nome:'JOAO DA SILVA',ra:'001',ativo:1,ultima_mensagem_id:1,nao_lidas:0},{idconversa:10,idcanal:1,codpessoa:1,canal:'Secretaria',responsavel:'MARIA DA SILVA',idaluno:4,aluno_nome:'ANA DA SILVA',ra:'002',ativo:1,ultima_mensagem_id:0,nao_lidas:0},{idconversa:11,idcanal:1,codpessoa:1,canal:'Secretaria',responsavel:'MARIA DA SILVA',idaluno:null,ativo:1,versao:1}];
let staff=false,legacyAssigned=false;
w.fetch=async(url,opt)=>{const route=url.replace('/api/chat/',''),data=opt.body&&JSON.parse(opt.body);calls.push({route,data});
return {ok:true,json:async()=>{
if(route==='canais')return {items:[{idcanal:1,nome:'Secretaria',ativo:1,atendente:staff}],admin:false};
if(route==='conversas'&&data)return {idconversa:10};
if(route==='conversas')return {items:conversations,more:false};
if(route.includes('/responsaveis?'))return {items:[{codpessoa:1,nome:'MARIA DA SILVA'}],more:false};
if(route.startsWith('canais/1/alunos'))return {items:students};
if(route==='conversas/11/aluno'){legacyAssigned=true;return {idconversa:11};}
if(/^conversas\/(9|10|11)$/.test(route)){const c=conversations.find(c=>String(c.idconversa)===route.split('/')[1]),a=students.find(a=>a.idaluno===(c.idaluno||(legacyAssigned?3:0)));return {...c,aluno:a?{...a,foto_url:'https://example.test/aluno.png',turmas:[{nome:'5 ANO A'}]}:null};}
if(route.includes('/mensagens'))return {items:[],more:false};return {lido:true};
}};};
w.eval(fs.readFileSync(path.join(__dirname,'../assets/chat.js'),'utf8'));await wait();
assert.match(d.querySelector('.erp-chat-list').textContent,/MARIA · JOAO · RA 001/);
d.querySelector('.erp-chat-conversation').click();await wait();
assert.equal(d.querySelector('.erp-chat-identity').textContent,'MARIA · JOAO · RA 001');
assert.ok(!d.querySelector('.erp-chat-identity').textContent.includes('SILVA'));
d.querySelector('.erp-chat-identity').click();await wait();
let modal=d.querySelector('dialog');assert.match(modal.textContent,/JOAO DA SILVA/);assert.match(modal.textContent,/5 ANO A/);assert.equal(modal.querySelector('img').src,'https://example.test/aluno.png');modal.querySelector('.erp-chat-close').click();
[...d.querySelectorAll('button')].find(b=>b.textContent==='Nova conversa').click();await wait();
modal=d.querySelector('dialog');modal.querySelector('select').value='1';modal.querySelector('select').dispatchEvent(new w.Event('change'));await wait();
const selects=modal.querySelectorAll('select');assert.equal(selects.length,2);assert.equal(selects[1].value,'');selects[1].value='4';modal.querySelector('form').requestSubmit();await wait();await wait();
assert.equal(calls.find(c=>c.route==='conversas'&&c.data).data.idaluno,'4');assert.equal(d.querySelector('.erp-chat-identity').textContent,'MARIA · ANA · RA 002');
[...d.querySelectorAll('.erp-chat-conversation')].find(b=>b.textContent.includes('Identificar aluno')).click();await wait();d.querySelector('.erp-chat-identity').click();await wait();
modal=d.querySelector('dialog');assert.match(modal.textContent,/histórico desta conversa/);modal.querySelector('select').value='3';modal.querySelector('form').requestSubmit();await wait();assert.equal(legacyAssigned,true);assert.ok(calls.some(c=>c.route==='conversas/11/aluno'&&c.data.idaluno==='3'));
console.log('PASS: cabeçalho com primeiros nomes e RA, modal com foto/turma, escolha entre irmãos e identificação do legado.');
w.close();
})().catch(e=>{console.error(e);process.exit(1);});
