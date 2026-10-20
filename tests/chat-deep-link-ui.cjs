const {JSDOM}=require('jsdom'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
(async()=>{
const dom=new JSDOM('<section data-erp-chat></section>',{url:'https://example.test/portal?erp_tela=chat&conversa=99',runScripts:'dangerously'}),w=dom.window,d=w.document,calls=[];w.EDERP={root:'/api/',nonce:'test'};
w.fetch=async(raw,opt)=>{const route=raw.replace('/api/chat/','');calls.push(route);return {ok:true,json:async()=>{
if(route==='canais')return {items:[],admin:false};if(route==='conversas')return {items:[],more:false};
if(route==='conversas/99')return {idconversa:99,idcanal:5,codpessoa:2,canal:'Secretaria',responsavel:'Maria da Silva',idaluno:1,aluno_nome:'João da Silva',ra:'123',ativo:1};
if(route.includes('/mensagens'))return {items:[{idmensagem:8,autor_nome:'Secretaria',texto:'Conversa correta',criado_em:'2026-10-20 12:00:00',anexos:[]}],more:false};
return {lido:true};
}};};w.eval(fs.readFileSync(path.join(__dirname,'../assets/chat.js'),'utf8'));await new Promise(r=>setTimeout(r,50));
assert.equal(d.querySelector('.erp-chat-identity').textContent,'Maria · João · RA 123');assert.ok(d.querySelector('.erp-chat').classList.contains('conversation-open'));assert.match(d.querySelector('.erp-chat-timeline').textContent,/Conversa correta/);assert.ok(calls.includes('conversas/99'));console.log('PASS: link abre a conversa correta mesmo fora da primeira página da lista.');w.close();
})().catch(e=>{console.error(e);process.exit(1)});
