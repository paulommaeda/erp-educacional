const {JSDOM,VirtualConsole}=require('jsdom'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const wait=()=>new Promise(r=>setTimeout(r,40));
async function run(withModel,manual=false){
 const errors=[],calls=[],vc=new VirtualConsole();vc.on('jsdomError',e=>{if(!e.message.includes('navigation'))errors.push(e);});
 const dom=new JSDOM('<main class="ederp"><section data-erp-screen="matriculas"></section></main>',{url:'https://school.test/portal?erp_tela=matriculas&novo=1',runScripts:'dangerously',virtualConsole:vc}),w=dom.window,d=w.document;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};w.EDERP={root:'https://school.test/api/',front:true,portal:'https://school.test/portal',period:7,nonce:'fixture',caps:{academic:true,people:true,admin:true}};
 w.fetch=async(raw,options={})=>{const u=new URL(raw),route=u.pathname.slice(5),body=options.body&&JSON.parse(options.body);calls.push({route,body,options});let result;
   if(body)result={total:1};
   else if(route==='modelos-contrato')result={items:withModel?[{idmodelo:'3',nome:'Modelo anual',versao_texto:'2',versao:'5',ativo:'1',padrao:'1'}]:[]};
   else if(route==='turmas/9/plano')result={idplano:'4',versao:'2',nome:'Plano anual',valor_anuidade:'12000.00'};
   else if(route==='configuracoes')result={codperiodo:7,periodos:[{codperiodo:7,codigo:'2027',descricao:'Ano letivo'}]};
   else if(route==='matriculas/filtros')result={cursos:[],turnos:[],turmas:[]};
   else if(route==='matriculas')result={items:[],total:0};
   else if(route==='secretaria/alunos')result={items:[{idaluno:'2',nome:'Aluno Teste',ra:'1234',ativo:1}],total:1};
   else if(route.startsWith('opcoes/')){const rows={periodos_letivos:[{id:7,label:'2027'}],cursos:[{id:4,label:'Fundamental'}],turnos:[{id:5,label:'Manhã'}],turmas:[{id:9,label:'6º ano'}]};result={items:rows[route.slice(7)]||[],more:false};}
   else result={items:[],total:0};return {ok:true,json:async()=>result};
 };
 for(const file of ['modals.js','action-menu.js','workflow.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets',file),'utf8'));await wait();await wait();
 for(const [name,value]of [['idcurso','4'],['idturno','5']]){const input=d.querySelector('dialog [name='+name+']');input.value=value;input.dispatchEvent(new w.Event('change'));}await wait();const classroom=d.querySelector('dialog [name=idturma]');classroom.value='9';classroom.dispatchEvent(new w.Event('change'));await wait();
 const model=d.querySelector('dialog [name=idmodelo]');assert.equal(model.required,false,'texto personalizado deve continuar disponível sem selecionar modelo');
 if(manual){model.value='';model.dispatchEvent(new w.Event('change'));d.querySelector('dialog [name=termo]').value='Texto personalizado para [aluno_nome].';}
 else if(withModel){assert.equal(model.value,'3');assert(d.querySelector('dialog [name=termo]').parentElement.hidden);}
 d.querySelector('dialog [name=primeiro_vencimento]').value='2027-01-31';[...d.querySelectorAll('button')].find(b=>b.textContent==='Selecionar alunos desta página').click();d.querySelector('dialog form').requestSubmit();await wait();
 const post=calls.find(c=>c.route==='matriculas/lote'&&c.body);assert(post,'o formulário de matrícula deve enviar a confirmação');assert.equal(post.body.idplano,'4');assert.deepEqual(post.body.alunos,['2']);
 if(withModel&&!manual){assert.equal(post.body.idmodelo,'3');assert.equal(post.body.modelo_versao,'5');assert.equal(post.body.termo,undefined);}else{assert.equal(post.body.idmodelo,undefined);assert.match(post.body.termo,/personalizado|prestação de serviços/);}
 assert.equal(errors.length,0);assert.equal(d.querySelector('dialog[open]'),null);dom.window.close();
}
(async()=>{await run(true);console.log('PASS: matrícula seleciona modelo padrão e envia versão para gerar texto individual');await run(true,true);console.log('PASS: secretaria pode optar por texto personalizado mesmo com modelo padrão');await run(false);console.log('PASS: matrícula continua funcionando quando não há modelos cadastrados');})().catch(e=>{console.error(e);process.exit(1);});
