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

async function main(){
    const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
    try{
        const staff=await login(browser,fixture.authorized);
        const {page,context}=staff;
        await page.goto(fixture.portal);
        assert.deepEqual(await page.locator('.erp-report-menu a').allTextContents(),['Parcelas']);
        const student=page.locator('[name=relatorio_aluno]');
        const month=page.locator('[name=relatorio_mes]');
        assert.equal(await student.inputValue(),'todos');
        assert.equal(await month.inputValue(),'todos');
        await student.selectOption(String(fixture.student));
        await month.selectOption('2027-01');
        const go=page.getByRole('button',{name:'Ver na tela'});
        await Promise.all([page.waitForURL(u=>u.searchParams.get('relatorio_mes')==='2027-01'),go.click()]);
        const table=page.locator('.erp-installment-report-table');
        assert.deepEqual(await table.locator('th').allTextContents(),['RA','Aluno','Parcela original','Desconto inc.','Pontualidade','Valor líquido (sem pontualidade)']);
        assert.equal(await table.locator('tbody tr').count(),3);
        assert.match(await table.textContent(),/R\$ 350,00R\$ 103,00R\$ 1\.003,00/);
        assert.doesNotMatch(await table.textContent(),/DASH-B/);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2),true);
        assert.equal(await page.locator('.erp-roster-scroll').evaluate(e=>e.scrollWidth>e.clientWidth),true);
        console.log('Prévia no celular: seis colunas e filtros combinados.');

        const exportLink=page.getByRole('link',{name:'Salvar em PDF'});
        const href=await exportLink.getAttribute('href');
        const url=new URL(href,fixture.portal);
        const response=await context.request.get(url.href);
        assert.equal(response.status(),200);
        assert.equal(response.headers()['content-type'],'application/pdf');
        assert.match(response.headers()['content-disposition'],/parcelas-2027-01-aluno-\d+\.pdf/);
        const path=join(directory,'parcelas.pdf');
        writeFileSync(path,await response.body());
        const info=execFileSync('pdfinfo',[path],{encoding:'utf8'});
        assert.match(info,/Page size:\s+841\.\d+ x 595\.\d+ pts \(A4\)/);
        const extracted=execFileSync('pdftotext',['-layout',path,'-'],{encoding:'utf8'});
        assert.match(extracted,/Janeiro\/2027/);
        assert.match(extracted,/R\$ 350,00\s+R\$ 103,00\s+R\$ 1\.003,00/);
        assert.doesNotMatch(extracted,/DASH-B|Fevereiro\/2027/);
        assert.match(execFileSync('pdfimages',['-list',path],{encoding:'utf8'}),/^\s*1\s+\d+\s+image\s/m);
        console.log('PDF baixado em A4 paisagem, com logo e a mesma seleção e valores da prévia.');

        const modified=new URL(url);
        modified.searchParams.set('relatorio_mes','todos');
        assert.equal((await context.request.get(modified.href)).status(),403);
        const denied=await login(browser,fixture.denied);
        assert.equal((await denied.context.request.get(url.href)).status(),403);
        const guest=await browser.newContext();
        assert.equal((await guest.request.get(url.href)).status(),403);
        await denied.page.goto(fixture.portal);
        assert.equal(await denied.page.locator('.erp-installment-report-table').count(),0);
        console.log('Acesso independente por perfil e download bloqueado para terceiros ou filtro alterado.');

        await student.selectOption('todos');
        await month.selectOption('todos');
        assert.equal(await page.locator('[data-erp-report-download]').isVisible(),false);
        await Promise.all([page.waitForURL(u=>u.searchParams.get('relatorio_mes')==='todos'),go.click()]);
        await page.setViewportSize({width:1280,height:900});
        assert.deepEqual(await page.locator('.erp-installment-report-month').allTextContents(),['Março/2027','Fevereiro/2027','Janeiro/2027']);
        assert.equal(await page.locator('.erp-installment-report-table tbody tr').count(),7);
        console.log('Todas as competências agrupam sete parcelas e excluem lançamentos cancelados e excluídos.');
    }finally{
        await browser.close();
    }
}
main().catch(e=>{console.error(e);process.exitCode=1});
