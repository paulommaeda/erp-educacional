const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const {dirname,join}=require('node:path');
const {chromium}=require('playwright');
const directory=dirname(process.env.WP_QA_DIR);
const fixture=JSON.parse(readFileSync(join(directory,'fixture.json'),'utf8'));

async function login(browser,account){
    const context=await browser.newContext({viewport:{width:390,height:844}});
    const page=await context.newPage();
    page.setDefaultTimeout(12000);
    await page.goto(fixture.portal);
    const form=page.locator('.erp-login-form');
    await form.locator('[name=login]').fill(account.login);
    await form.locator('[name=password]').fill(account.password);
    await Promise.all([page.waitForURL(/erp_tela=inicio/),form.locator('button[type=submit]').click()]);
    return {context,page};
}


async function send(page, name) {
    await page.goto(fixture.public);
    const form = page.locator('[data-erp-scholarship-form] form');
    await form.getByText('Etapa 1 de 4', { exact: false }).waitFor();
    await form.getByLabel('Ensino Fundamental II', { exact: true }).check();
    await form.getByLabel('6º Ano', { exact: true }).check();
    await form.locator('[name=turno_fundamental_2][value=Matutino]').check();
    await form.getByRole('button', { name: 'Próximo' }).click();
    await form.getByLabel('Nome Completo do Aluno').fill(name);
    await form.getByLabel('Data de Nascimento').fill('2015-01-01');
    await form.locator('[name=vive_com][value=Outros]').check();
    await form.locator('[name=outros_moradia]').fill('Outros responsáveis');
    await form.getByLabel('Divorciados', { exact: true }).check();
    await form.getByLabel('Mãe', { exact: true }).check();
    await form.getByLabel('Número de Filhos').fill('2');
    await form.getByLabel('Idade dos Filhos').fill('10 e 12');
    await form.getByRole('button', { name: 'Próximo' }).click();
    for(const [label,value] of [['Profissão Mãe','Analista'],['Renda Individual Mãe','1.500'],['Profissão Pai','Autônomo'],['Renda Individual Pai','2.000']]){
        await form.getByLabel(label).fill(value);
    }
    await form.getByRole('button', { name: 'Próximo' }).click();
    await form.getByLabel('Nome do Solicitante').fill('Pessoa de Teste');
    await form.getByLabel('E-mail').fill('familia@example.test');
    await form.getByLabel('Celular/Whatsapp').fill('41999999999');
    await form.getByLabel('Porcentagem de desconto').fill('12,5%');
    await form.getByLabel('Justificativa do Pedido').fill('Justificativa de teste');
    await page.waitForTimeout(3200);
    await form.getByRole('button', { name: 'Enviar requerimento' }).click();
    await page.getByRole('heading', { name: 'Requerimento recebido' }).waitFor();
    assert.match(await page.locator('.erp-admission-success').textContent(), /BOL-[0-9A-F]{20}/);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2),true);
}

async function main(){
    const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
    try{
        const guest=await browser.newPage({viewport:{width:390,height:844}});
        guest.on('pageerror',e=>console.error('Formulário:',e.message));
        await send(guest, 'Aluno Bolsista');
        await send(guest, 'Aluno sem vínculo');
        console.log('Quatro etapas e condições conferidas no formulário público pelo celular.');
        const {page}=await login(browser,fixture.reviewer);
        page.on('pageerror',e=>console.error('Análise:',e.message));
        await page.goto(fixture.portal);
        const cards=page.locator('.erp-scholarship-list-item');
        await cards.filter({hasText:'Aluno Bolsista'}).getByRole('button',{name:'Abrir requerimento'}).click();
        let dialog=page.getByRole('dialog');
        await dialog.getByText(/RA BOLSISTA/).waitFor();
        assert.match(await dialog.textContent(),/Justificativa de teste/);
        await dialog.getByLabel('Resultado').selectOption('aprovado');
        await dialog.getByLabel('Percentual a aprovar').fill('15');
        await dialog.getByRole('button',{name:'Registrar decisão'}).click();
        await dialog.getByText(/Percentual aprovado · 15,00%/).waitFor();
        await dialog.getByRole('button',{name:'Fechar'}).click();
        await cards.filter({hasText:'Aluno sem vínculo'}).getByRole('button',{name:'Abrir requerimento'}).click();
        dialog=page.getByRole('dialog');
        await dialog.getByText('Vincular aluno manualmente',{exact:true}).click();
        await dialog.getByLabel('Nome ou RA').fill('BOLSISTA');
        await dialog.getByRole('button',{name:'Buscar alunos'}).click();
        const select=dialog.getByLabel('Aluno encontrado');
        await select.selectOption({ index: 1 });
        await dialog.getByRole('button',{name:'Confirmar vínculo'}).click();
        await dialog.getByText(/RA BOLSISTA/).waitFor();
        await dialog.getByLabel('Resultado').selectOption('rejeitado');
        await dialog.getByRole('button',{name:'Registrar decisão'}).click();
        await dialog.getByText(/^Rejeitado · Recebido em/).waitFor();
        const admin=await login(browser,fixture.admin);
        await admin.page.goto(fixture.portal);
        await admin.page.getByRole('button',{name:'Configurar formulário público'}).click();
        const settings=admin.page.getByRole('dialog');
        assert.match(await settings.getByLabel('E-mails da administração').inputValue(),/administracao@example.test/);
        await settings.getByLabel('E-mails da administração').fill('outro@example.test');
        await settings.getByRole('button',{name:'Salvar configurações'}).click();
        await settings.getByText('Configurações salvas.').waitFor();
        console.log('Análise, vínculo manual, aprovação com percentual editável, rejeição e configurações conferidos no portal.');
    }finally{
        await browser.close();
    }
}
main().catch(e=>{console.error(e);process.exitCode=1});
