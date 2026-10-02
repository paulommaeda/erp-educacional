const {JSDOM}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const assets=path.join(__dirname,'../assets'),wait=()=>new Promise(r=>setTimeout(r,25));
(async()=>{
const dom=new JSDOM('<div class="ederp" data-erp-screen="academico"></div>',{runScripts:'dangerously',url:'https://example.test/portal'}),w=dom.window,d=w.document,calls=[];
w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'))};
w.EDERP={root:'https://example.test/api/',period:7,caps:{admin:true}};
let fail=false;
w.fetch=async(raw,opt={})=>{const u=new URL(raw),route=u.pathname.slice(5),body=opt.body&&JSON.parse(opt.body);calls.push({route,body});let result;
if(body)result=fail?{message:'Código já utilizado'}:{id:7};
else if(route.startsWith('cadastros/periodos_letivos/'))result={codigo:'2027',descricao:'Futuro',codperiodo_proximo:8};
else if(route.startsWith('cadastros/'))result={items:[{codperiodo:7,idcurso:4,idturno:4,idplano:4,idturma:9,codigo:'TESTE',descricao:'Atual',nome:'Registro teste',data_inicio:'2026-01-01',data_fim:'2026-12-31',versao:3,status:route.endsWith('turmas')?'ativa':'aberto',ativo:1,capacidade:20,valor_anuidade:'1200.00'}],total:1};
else if(route.startsWith('opcoes/'))result={items:[{id:route.endsWith('periodos_letivos')?7:4,label:'Opção'}],more:false};
else result=[];return {ok:!(body&&fail),json:async()=>result};};
for(const file of ['modals.js','workflow.js'])w.eval(fs.readFileSync(path.join(assets,file),'utf8'));await wait();
const click=(text,scope=d)=>{const b=[...scope.querySelectorAll('button')].find(x=>x.textContent===text);assert.ok(b,text);b.click();};
for(const [area,label] of [['Períodos letivos','período letivo'],['Cursos','curso'],['Turnos','turno'],['Planos de pagamento','plano de pagamento'],['Turmas','turma']]){
 click(area);await wait();assert.equal(d.querySelector('dialog[open]'),null);
 assert.equal([...d.querySelectorAll('form.ederp-form')].filter(f=>!f.closest('dialog')).length,0);
 click('Cadastrar '+label);await wait();let dialog=d.querySelector('dialog[open]');assert.ok(dialog);assert.equal(dialog.querySelector('h2').textContent,'Cadastrar '+label);
 click('Fechar',dialog);assert.equal(d.querySelector('dialog[open]'),null);assert.equal(d.activeElement.textContent,'Cadastrar '+label);
 click('Editar');await wait();dialog=d.querySelector('dialog[open]');assert.ok(dialog);assert.equal(dialog.querySelector('[name=codigo]').value,'TESTE');
 if(area==='Cursos'){
  const form=dialog.querySelector('form');form.querySelector('[name=nome]').value='NOME ALTERADO';fail=true;form.requestSubmit();await wait();assert.equal(dialog.open,true);assert.match(dialog.textContent,/Código já utilizado/);assert.equal(form.querySelector('[name=nome]').value,'NOME ALTERADO');
  fail=false;form.requestSubmit();await wait();assert.equal(dialog.open,false);const request=calls.filter(c=>c.body).at(-1);assert.equal(request.route,'cadastros/cursos/4');assert.equal(request.body.versao,3);assert.equal(request.body.nome,'NOME ALTERADO');
 }else click('Fechar',dialog);
 console.log('PASS: '+area+' cria e edita em modal, mantém listagem e devolve foco');
}
// Creation validation and one POST, then refreshed directory.
click('Cursos');await wait();click('Cadastrar curso');await wait();let f=d.querySelector('dialog[open] form');let before=calls.filter(c=>c.body).length;f.requestSubmit();await wait();assert.equal(calls.filter(c=>c.body).length,before);f.querySelector('[name=codigo]').value='NOVO';f.querySelector('[name=nome]').value='NOVO CURSO';f.requestSubmit();await wait();assert.equal(calls.filter(c=>c.body).length,before+1);assert.equal(d.querySelector('dialog[open]'),null);assert.match(d.body.textContent,/Cadastro salvo com sucesso/);
console.log('PASS: validação, erro sem perda dos dados, versão e atualização da lista após salvar');
// Closing must be blocked during an in-flight save, including Escape.
click('Cadastrar curso');await wait();f=d.querySelector('dialog[open] form');f.dataset.saving='1';const dialog=f.closest('dialog');click('Fechar',dialog);assert.equal(dialog.open,true);const cancel=new w.Event('cancel',{cancelable:true});dialog.dispatchEvent(cancel);assert.equal(cancel.defaultPrevented,true);f.dataset.saving='0';click('Fechar',dialog);assert.equal(dialog.open,false);console.log('PASS: fechamento bloqueado somente durante envio');dom.window.close();
})().catch(e=>{console.error(e);process.exit(1)});
