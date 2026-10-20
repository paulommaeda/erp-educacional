const {JSDOM}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict'),wait=()=>new Promise(r=>setTimeout(r,25));
const fixture=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/student-record.json'),'utf8'));
async function boot(admin=true){
 const dom=new JSDOM('<main data-erp-screen="alunos"></main>',{runScripts:'dangerously',url:'https://example.test/portal?erp_tela=alunos&aluno=1'}),w=dom.window,d=w.document;
 w.EDERP=structuredClone(fixture.settings);if(!admin){w.EDERP.caps.admin=false;w.EDERP.studentSections={};w.EDERP.additionalFields=[];}
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 w.fetch=async()=>({ok:true,json:async()=>structuredClone(fixture.sheet)});
 for(const f of ['modals.js','person-fields.js','workflow.js'])w.eval(fs.readFileSync(path.join(__dirname,'../assets',f),'utf8'));await wait();return {dom,w,d};
}
(async()=>{
 let x=await boot();assert.equal(x.d.querySelector('table'),null);
 assert.ok(x.d.querySelector('a[href="mailto:carlos%40example.test"]'));assert.ok(x.d.body.textContent.includes('RUA DAS FLORES'));assert.ok(x.d.body.textContent.includes('CURITIBA'));
 assert.ok(x.d.body.textContent.includes('ALERGIA A AMENDOIM'));assert.ok(x.d.body.textContent.includes('RELATÓRIO DE ACOMPANHAMENTO'));assert.ok(![...x.d.querySelectorAll('dt')].some(n=>n.textContent==='Medicação condicional'));
 const launch=[...x.d.querySelectorAll('.erp-modal-trigger')].find(b=>b.textContent==='Editar Ficha médica');assert.ok(launch);launch.click();assert.ok(x.d.querySelector('dialog[open] [name=extra__ficha_medica]'));assert.equal(x.d.querySelector('dialog[open] [name=extra__laudos]'),null);x.d.querySelector('dialog[open] .erp-modal-header button').click();
 [...x.d.querySelectorAll('[data-tab]')].find(b=>b.dataset.tab==='responsaveis').click();
 assert.equal(x.d.querySelectorAll('.erp-guardian-card').length,2);const card=x.d.querySelector('.erp-guardian-card');
 assert.equal(card.querySelectorAll('.erp-guardian-roles .erp-badge').length,3);assert.ok(card.querySelector('a[href="mailto:camila%40example.test"]'));assert.ok(card.querySelector('a[href="tel:41999990002"]'));assert.deepEqual([...card.querySelectorAll('.erp-guardian-actions button')].map(b=>b.textContent),['Editar atribuições','Excluir']);
 console.log('PASS: ficha com contato/endereço, áreas condicionais, edição em modal e contatos/ações dos responsáveis.');x.dom.window.close();
 x=await boot(false);assert.ok(!x.d.body.textContent.includes('CURITIBA'));assert.ok(!x.d.body.textContent.includes('carlos@example.test'));assert.ok(!x.d.body.textContent.includes('20/11/2012'));assert.ok(!x.d.body.textContent.includes('ALERGIA A AMENDOIM'));assert.ok(!x.d.body.textContent.includes('Ficha médica'));assert.ok(!x.d.querySelector('.erp-modal-trigger'));console.log('PASS: usuário sem áreas liberadas só recebe a ficha comum na interface.');x.dom.window.close();
})().catch(e=>{console.error(e);process.exit(1)});
