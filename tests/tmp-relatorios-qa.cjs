const assert=require('node:assert/strict');
const {readFileSync,writeFileSync}=require('node:fs');
const {dirname,join}=require('node:path');
const {execFileSync}=require('node:child_process');
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

const columns=['Contrato e aluno','Período e turma','Condições','Venc.','Pontualidade','Descontos incondicionais','Assinado'];

async function main(){
    const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
    try{
        const {page,context}=await login(browser,fixture.authorized);
        await page.goto(fixture.portal);
        assert.deepEqual(await page.locator('.erp-report-menu a').allTextContents(),['Contratos']);
        await Promise.all([
            page.waitForURL(u=>u.searchParams.get('contratos_periodo')===String(fixture.period)),
            page.locator('[name=contratos_periodo]').selectOption(String(fixture.period))
        ]);
        const turma=page.locator('[name=contratos_turma]');
        assert.equal(await turma.inputValue(),'todas');
        assert.match(await turma.textContent(),/TURMA B/);
        await page.locator('[name=contratos_assinatura]').selectOption('assinado');
        await page.locator('[name=contratos_busca]').fill('ALUNO DASH-A');
        await Promise.all([
            page.waitForURL(u=>u.searchParams.get('contratos_assinatura')==='assinado'),
            page.getByRole('button',{name:'Ver na tela'}).click()
        ]);
        const table=page.locator('.erp-contracts-report-table');
        assert.deepEqual(await table.locator('th').allTextContents(),columns);
        assert.equal(await table.locator('tbody tr').count(),1);
        assert.match(await table.textContent(),/R\$ 2\.276,50/);
        assert.equal(await table.locator('input[type=checkbox]').isChecked(),true);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2),true);
        const href=await page.getByRole('link',{name:'Salvar em PDF'}).getAttribute('href');
        const url=new URL(href,fixture.portal);
        const response=await context.request.get(url.href);
        assert.equal(response.status(),200);
        assert.equal(response.headers()['content-type'],'application/pdf');
        const pdf=join(directory,'contratos.pdf');
        writeFileSync(pdf,await response.body());
        assert.match(execFileSync('pdfinfo',[pdf],{encoding:'utf8'}),/Page size:\s+841\.\d+ x 595\.\d+ pts \(A4\)/);
        const extracted=execFileSync('pdftotext',['-layout',pdf,'-'],{encoding:'utf8'});
        assert.match(extracted,/DASH-A/);
        assert.match(extracted,/R\$ 2\.276,50/);
        assert.doesNotMatch(extracted,/DASH-B/);
        assert.match(execFileSync('pdfimages',['-list',pdf],{encoding:'utf8'}),/^\s*1\s+\d+\s+image\s/m);
        const tampered=new URL(url);
        tampered.searchParams.set('contratos_assinatura','nao_assinado');
        assert.equal((await context.request.get(tampered.href)).status(),403);
        const denied=await login(browser,fixture.denied);
        assert.equal((await denied.context.request.get(url.href)).status(),403);
        console.log('Prévia e PDF A4 paisagem usam os mesmos filtros, com acesso por perfil.');

        await turma.selectOption(String(fixture.classB));
        await page.locator('[name=contratos_assinatura]').selectOption('nao_assinado');
        await page.locator('[name=contratos_busca]').fill('');
        await Promise.all([page.waitForURL(u=>u.searchParams.get('contratos_turma')===String(fixture.classB)),page.getByRole('button',{name:'Ver na tela'}).click()]);
        assert.equal(await table.locator('tbody tr').count(),1);
        assert.match(await table.textContent(),/DASH-B/);
        assert.equal(await table.locator('input[type=checkbox]').isChecked(),false);

        const discounts=await login(browser,fixture.discounts);
        assert.equal((await discounts.context.request.get(url.href)).status(),403);
        discounts.page.on('pageerror',error=>console.error('Página de bolsas:',error.message));
        await discounts.page.goto(fixture.discountsUrl);
        await discounts.page.setViewportSize({width:1400,height:900});
        const listing=discounts.page.locator('[data-erp-contracts] .erp-contract-table');
        await listing.waitFor().catch(async e=>{console.error(await discounts.page.locator('main').innerText());throw e;});
        assert.deepEqual(await listing.locator('th').allTextContents(),columns);
        const open=listing.getByRole('button',{name:fixture.contract,exact:true});
        await open.click();
        const dialog=discounts.page.getByRole('dialog',{name:'Resumo do contrato '+fixture.contract});
        await dialog.getByRole('heading',{name:'Parcelas do contrato'}).waitFor();
        assert.match(await dialog.textContent(),/BOLSA FIXA/);
        assert.match(await dialog.textContent(),/Responsável financeiro/);
        await dialog.getByRole('button',{name:'Gerenciar bolsas e descontos'}).click();
        await discounts.page.getByRole('dialog').waitFor();
        console.log('Tela de bolsas preserva a edição pelo modal e exibe descontos por parcela.');

        const admin=await login(browser,fixture.admin);
        admin.page.on('pageerror',error=>console.error('Área administrativa:',error.message));
        await admin.page.goto(fixture.discountsUrl);
        const adminListing=admin.page.locator('[data-erp-contracts] .erp-contract-table');
        const corrupted=adminListing.locator('tr').filter({hasText:'ALUNO DESC-SEM-ORIGEM'});
        await corrupted.waitFor();
        assert.match(await corrupted.textContent(),/05/);
        assert.match(await corrupted.textContent(),/R\$\s*16\.236,00/);
        assert.match(await corrupted.textContent(),/R\$\s*300,00/);
        await corrupted.getByRole('button',{name:fixture.example,exact:true}).click();
        await admin.page.getByRole('button',{name:'Regerar ou trocar plano'}).click();
        const edit=admin.page.getByRole('dialog',{name:'Regerar contrato '+fixture.example});
        await edit.getByLabel('Motivo da alteração').fill('Conferência de teste');
        await edit.getByLabel('Confirmo a alteração das parcelas disponíveis.').check();
        await edit.getByRole('button',{name:'Salvar'}).click();
        await admin.page.getByText(/Contrato recalculado/).waitFor();
        await corrupted.waitFor();
        assert.doesNotMatch(await corrupted.textContent(),/R\$\s*300,00/);

        const pending=adminListing.locator('tr').filter({hasText:'ALUNO QUIT-PEND'});
        await pending.getByRole('button',{name:fixture.pending,exact:true}).click();
        await admin.page.getByRole('button',{name:'Regerar ou trocar plano'}).click();
        const plan=admin.page.getByRole('dialog',{name:'Regerar contrato '+fixture.pending});
        const options=plan.getByLabel('Plano de pagamento');
        const replacement=await options.locator('option').filter({hasText:'Novo plano QA'}).getAttribute('value');
        await options.selectOption(replacement);
        await plan.getByLabel('Motivo da alteração').fill('Atualiza plano em teste');
        await plan.getByLabel('Confirmo a alteração das parcelas disponíveis.').check();
        await plan.getByRole('button',{name:'Salvar'}).click();
        await admin.page.getByText(/Plano de pagamento atualizado/).waitFor();
        await pending.waitFor();
        assert.match(await pending.textContent(),/R\$\s*18\.000,00/);
        console.log('Recálculo e troca de plano confirmados pelo portal.');

    }finally{
        await browser.close();
    }
}
main().catch(e=>{console.error(e);process.exitCode=1});
