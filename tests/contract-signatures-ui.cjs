const assert = require('node:assert/strict');
const {setup} = require('./contracts-ui.cjs');
const wait = () => new Promise(resolve => setTimeout(resolve, 30));
function click(root, label) {
    const button = [...root.querySelectorAll('button')].find(node => node.textContent === label);
    assert.ok(button, label);
    button.click();
}
(async () => {
    const x = await setup({contract:{responsavel:'RESPONSÁVEL TESTE',responsavel_email:'familia@example.test'}});
    const original = x.w.fetch, calls = [];
    const settings = {base:'https://signing.example.test/wp-json/edu-sign/v1',usuario:'gestor',tem_senha:true,tem_segredo:true,retorno:'https://erp.example.test/wp-json/erp-educacional/v1/assinatura-eletronica/retorno'};
    let rows = [], signed = false;
    const row = {idenvio:4,revisao:1,situacao:'sent',uuid:'7c3fd426-269b-4b93-9513-255d55881215',referencia:'erp-teste/coligada-1/contrato-1/rev-1'};
    x.w.fetch = async (raw, options = {}) => {
        const route = new URL(raw).pathname.replace('/api/',''), body = options.body && JSON.parse(options.body);
        if (!route.includes('assinatura')) {
            const response = await original(raw, options);
            if (signed && route === 'contratos/1' && !body) return {ok:true,json:async()=>({...await response.json(),assinado_em:'2026-11-20 12:00:00',assinado_por_nome:'RESPONSÁVEL TESTE'})};
            return response;
        }
        calls.push({route,body,headers:options.headers});
        let result;
        if (route.includes('configuracao')) result = settings;
        else {
            if (route.endsWith('/enviar')) rows = [row];
            if (route.endsWith('/consultar')) {signed = true;rows = [{...row,situacao:'signed',assinado_em:'2026-11-20 12:00:00',vinculado:1}];}
            result = {configurado:true,items:rows};
        }
        return {ok:true,json:async()=>result};
    };
    click(x.d,'Configurar assinatura eletrônica');
    await wait();
    let dialog = x.d.querySelector('dialog'), form = dialog.querySelector('form');
    assert.equal(form.querySelector('[name=retorno]').value,settings.retorno);
    for(const name of ['senha','segredo'])assert.equal(form.querySelector('[name='+name+']').value,'');
    form.requestSubmit();await wait();
    assert.equal(calls.find(c=>c.body).body.base,settings.base);
    click(x.d,'Ver contrato');await wait();
    dialog = x.d.querySelector('dialog');
    assert.match(dialog.textContent,/familia@example.test/);
    click(dialog,'Enviar PDF para assinatura');await wait();
    const issue = calls.find(c=>c.route.endsWith('/enviar'));
    assert.ok(issue.headers['Idempotency-Key']);
    assert.equal(issue.body.versao,'4');
    assert.match(dialog.textContent,/Convite solicitado/);
    assert.equal([...dialog.querySelectorAll('button')].some(b=>b.textContent==='Enviar PDF para assinatura'),false);
    click(dialog,'Consultar situação');await wait();await wait();
    assert.match(x.d.querySelector('dialog').textContent,/Baixar PDF assinado/);
    assert.equal(x.d.querySelector('dialog [name=assinado]').checked,true);
    assert.deepEqual(x.errors,[]);x.dom.window.close();
    const restricted=await setup({caps:{discounts:true}});
    assert.doesNotMatch(restricted.d.body.textContent,/Configurar assinatura eletrônica|Enviar PDF para assinatura/);
    restricted.dom.window.close();
    console.log('CONTRACT_SIGNATURES_UI_OK: configuração, destinatário, emissão, protocolo, consulta e PDF assinado');
})().catch(error=>{console.error(error);process.exit(1);});
