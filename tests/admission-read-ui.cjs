const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const dom=new JSDOM('<main id="root"></main>',{url:'https://school.test/portal',runScripts:'dangerously'}),w=dom.window,d=w.document,calls=[];
 w.EDERP={root:'https://school.test/wp-json/erp-educacional/v1/',nonce:'fixture',coligada:2};w.fetch=async(url,opt)=>{calls.push({url,opt});return {ok:true,json:async()=>({conexoes:[]})};};
 w.eval(fs.readFileSync(__dirname+'/../assets/external-api.js','utf8'));await w.EDERPExternalApi.settings(d.querySelector('#root'));
 const section=d.querySelector('.erp-api-reading'),input=section.querySelector('input');assert.equal(input.value,'https://school.test/wp-json/erp-educacional/v1/integracao/requerimentos?codcoligada=2');assert.equal(input.readOnly,true);input.focus();assert.equal(input.selectionStart,0);assert.equal(input.selectionEnd,input.value.length);assert.match(section.textContent,/Integração: leitura de requerimentos/);assert.match(section.textContent,/coligada autorizada/);assert.match(section.textContent,/Senha de aplicativo/);assert.equal(calls.length,1);assert.equal(calls[0].opt.method,'GET');assert.equal(d.querySelectorAll('input[type=password]').length,0);
 console.log('ADMISSION_READ_UI_OK: endereço da coligada, cópia por seleção e orientação de acesso sem expor senha');w.close();
})().catch(e=>{console.error(e);process.exit(1);});
