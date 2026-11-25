const assert = require('node:assert/strict');
const {readFileSync, writeFileSync} = require('node:fs');
const {join} = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const dir = require('node:path').dirname(process.env.WP_QA_DIR);
const fixture = JSON.parse(readFileSync(join(dir,'fixture.json'),'utf8'));
const origin = new URL(fixture.portal).origin;

function reportUrl(report, period, group) {
    const result = new URL(fixture.portal);
    if(report)result.searchParams.set('relatorio',report);
    if(period)result.searchParams.set('relatorio_periodo',period);
    if(group)result.searchParams.set('relatorio_turma',group);
    return result.href;
}
async function login(browser, account, viewport={width:390,height:844}){
    const context = await browser.newContext({viewport});
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    await page.goto(fixture.portal);
    const form = page.locator('.erp-login-form');
    await form.locator('input[name=login]').fill(account.login);
    await form.locator('input[name=password]').fill(account.password);
    await Promise.all([page.waitForURL(/erp_tela=inicio/),form.locator('button[type=submit]').click()]);
    return {context,page};
}
async function applyFilters(page,type,period,group,statuses=[]){
    await page.goto(reportUrl(type,period));
    await page.locator('select[name=relatorio_turma]').selectOption(String(group));
    if(statuses.length){
        await page.locator('.erp-report-status summary').click();
        for(const status of statuses)await page.locator('input[value="'+status+'"]').check();
        await page.locator('.erp-report-status summary').click();
    }
    await Promise.all([
        page.waitForURL(url=>url.searchParams.get('relatorio_turma')===String(group)&&url.searchParams.get('relatorio')===type),
        page.getByRole('button',{name:'Ver na tela'}).click()
    ]);
}
async function pdf(context,page,filename){
    const here = new URL(await page.getByRole('link',{name:'Salvar em PDF'}).getAttribute('href'),origin);
    const response = await context.request.get(here.href);
    if(response.status()!==200)console.error('Resposta PDF', (await response.text()).substring(0,800));
    assert.equal(response.status(),200);
    assert.equal(response.headers()['content-type'],'application/pdf');
    assert.match(response.headers()['content-disposition'],/^attachment; filename=".*\.pdf"/);
    const bytes=await response.body();
    assert.equal(bytes.subarray(0,5).toString(),'%PDF-');
    const file=join(dir,filename);
    writeFileSync(file,bytes);
    return {
        url:here.href,
        info:execFileSync('pdfinfo',[file],{encoding:'utf8'}),
        text:execFileSync('pdftotext',['-layout',file,'-'],{encoding:'utf8'}),
        images:execFileSync('pdfimages',['-list',file],{encoding:'utf8'})
    };
}
async function main(){
    const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
    try{
        const {context:staff,page}=await login(browser,fixture.staff);
        await page.goto(reportUrl('alunos'));
        assert.deepEqual(await page.locator('.erp-report-menu a').allTextContents(),['Lista de assinaturas','Alunos']);
        const group=page.locator('select[name=relatorio_turma]');
        assert.equal(await group.locator('option[value=todas]').textContent(),'Todas');
        assert.equal(await page.locator('select[name=relatorio_periodo]').inputValue(),String(fixture.period));

        await applyFilters(page,'alunos',fixture.period,'todas',['cursando','reservado']);
        const roster=page.locator('.erp-roster-table');
        await roster.waitFor();
        assert.deepEqual(await roster.locator('th').allTextContents(),['RA','Nome','Email','Turma','Período Letivo','Pai','Email Pai','Telefone Pai','Mãe','Email Mãe','Telefone Mãe','Outro Res','Telefone','Email']);
        assert.equal(await roster.locator('tbody').count(),43);
        const primary=roster.locator('tbody').filter({has:page.getByText('0001',{exact:true})});
        const first=primary.locator('tr').first();
        assert.deepEqual(await first.locator('td').allTextContents(),['0001','ÁLUNO TESTE 01','aluno.relatorio+turma@exemplo.test','5º ANO A','2027','PAULO PAI','pai@exemplo.test','41990000001','MARIA MÃE','mae@exemplo.test','41990000002','ANA AVÓ','41990000003','avo@exemplo.test']);
        assert.deepEqual((await primary.locator('tr').last().locator('td').allTextContents()).slice(-3),['SOFIA TIA','41990000004','tia@exemplo.test']);
        assert.doesNotMatch(await roster.textContent(),/Ano de teste|CANCELADO|TRANSFERIDO|EXCLUÍDO|VÍNCULO|PRÓPRIO ALUNO|ALUNA OUTRA COLIGADA/);
        assert.match(await roster.textContent(),/ALUNA RESERVADA/);
        assert.equal(await page.locator('.erp-roster-scroll').evaluate(el=>el.scrollWidth>el.clientWidth),true);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2),true);
        assert.deepEqual(await roster.locator('tbody').evaluateAll(list=>list.slice(0,2).map(row=>getComputedStyle(row.querySelector('td')).backgroundColor)),['rgb(247, 247, 247)','rgb(255, 255, 255)']);
        await page.setViewportSize({width:1280,height:900});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2),true);
        console.log('Prévia Alunos em celular e desktop: 14 colunas, dados, vínculos atuais e dois status.');

        const rosterPdf=await pdf(staff,page,'alunos.pdf');
        assert.match(rosterPdf.info,/Page size:\s+841\.\d+ x 595\.\d+ pts \(A4\)/);
        assert.match(rosterPdf.info,/Pages:\s+2\b/);
        const rosterPages=rosterPdf.text.split('\f').filter(x=>x.trim());
        assert.equal(rosterPages.length,2);
        for(const p of rosterPages){
            assert.match(p,/RA\s+Nome\s+Email\s+Turma\s+Período\s+Pai\s+Email Pai/);
            assert.match(p,/Mãe\s+Email Mãe\s+Telefone Mãe\s+Outro Res\s+Telefone\s+Email/);
        }
        assert.match(rosterPdf.text,/ALUNA RESERVADA/);
        assert.match(rosterPdf.text,/PAULO PAI.*pai@exemplo.test.*41990000001.*MARIA MÃE.*mae@exemplo.test/);
        assert.match(rosterPdf.text,/ANA AVÓ.*41990000003.*avo@exemplo.test/);
        assert.match(rosterPdf.text,/SOFIA TIA.*41990000004.*tia@exemplo.test/);
        assert.match(rosterPdf.images,/^\s*1\s+\d+\s+image\s/m);
        assert.match(rosterPdf.images,/^\s*2\s+\d+\s+image\s/m);
        assert.doesNotMatch(rosterPdf.text,/Ano de teste|ALUNO OUTRA|VÍNCULO|CANCELADO|TRANSFERIDO/);
        const tampered=new URL(rosterPdf.url);
        tampered.searchParams.delete('relatorio_situacao[0]');
        assert.equal((await staff.request.get(tampered.href)).status(),403);
        console.log('PDF A4 em paisagem: 14 colunas por página, paginação, logo e filtros consistentes.');

        await applyFilters(page,'alunos',fixture.period,fixture.classB,[]);
        assert.equal(await roster.locator('tbody').count(),1);
        assert.match(await roster.textContent(),/ALUNA RESERVADA/);
        await applyFilters(page,'alunos',fixture.period,'todas',[]);
        assert.equal(await roster.locator('tbody').count(),45);
        assert.match(await roster.textContent(),/CANCELADO/);
        assert.match(await roster.textContent(),/TRANSFERIDO/);
        await applyFilters(page,'alunos',fixture.previous,'todas',['aprovado']);
        assert.match(await roster.textContent(),/ALUNA ANO ANTERIOR/);
        assert.doesNotMatch(await roster.textContent(),/2027|Ano de teste/);
        await page.goto(reportUrl('alunos',fixture.other,fixture.otherClass));
        assert.match(await page.getByRole('alert').textContent(),/coligada atual/);
        assert.equal(await roster.count(),0);
        console.log('Turma única, Todas, todas as situações, período anterior e isolamento da coligada.');

        await applyFilters(page,'assinaturas',fixture.period,'todas');
        const signatures=page.locator('.erp-signature-sheet');
        assert.equal(await signatures.count(),2);
        assert.match(await signatures.nth(0).textContent(),/5º ANO A/);
        assert.match(await signatures.nth(1).textContent(),/5º ANO D.*ALUNA RESERVADA/);
        const signaturesPdf=await pdf(staff,page,'assinaturas-todas.pdf');
        assert.match(signaturesPdf.info,/Page size:\s+595\.\d+ x 841\.\d+ pts \(A4\)/);
        assert.match(signaturesPdf.info,/Pages:\s+3\b/);
        assert.match(signaturesPdf.text,/5º ANO D/);
        assert.doesNotMatch(signaturesPdf.text,/CANCELADO|PAULO PAI/);
        await applyFilters(page,'assinaturas',fixture.period,fixture.class);
        assert.equal(await signatures.count(),1);
        console.log('Lista de assinaturas por turma e Todas mantém folhas separadas e contatos ausentes.');

        const rosterOnly=await login(browser,fixture.rosterOnly);
        await rosterOnly.page.goto(reportUrl('alunos',fixture.previous,'todas'));
        assert.deepEqual(await rosterOnly.page.locator('.erp-report-menu a').allTextContents(),['Alunos']);
        assert.equal(await rosterOnly.page.locator('.journey-menu').first().getByRole('link',{name:'Matrículas',exact:true}).count(),0);
        assert.match(await rosterOnly.page.locator('.erp-roster-table').textContent(),/ALUNA ANO ANTERIOR/);
        const own=await pdf(rosterOnly.context,rosterOnly.page,'somente-alunos.pdf');
        await rosterOnly.page.goto(reportUrl('assinaturas',fixture.period,'todas'));
        assert.match(await rosterOnly.page.getByRole('alert').textContent(),/acesso a este relatório/);
        assert.equal((await rosterOnly.context.request.get(signaturesPdf.url)).status(),403);
        const signaturesOnly=await login(browser,fixture.signaturesOnly);
        await signaturesOnly.page.goto(reportUrl('assinaturas',fixture.period,'todas'));
        assert.deepEqual(await signaturesOnly.page.locator('.erp-report-menu a').allTextContents(),['Lista de assinaturas']);
        assert.equal((await signaturesOnly.context.request.get(rosterPdf.url)).status(),403);
        const parent=await login(browser,fixture.parent);
        assert.equal((await parent.context.request.get(rosterPdf.url)).status(),403);
        const anonymous=await browser.newContext();
        assert.equal((await anonymous.request.get(rosterPdf.url)).status(),403);

        const admin=await login(browser,fixture.admin,{width:1280,height:900});
        const permissions=new URL(fixture.portal);
        permissions.searchParams.set('erp_tela','perfis');
        await admin.page.goto(permissions.href);
        const item=admin.page.locator('details.ederp-card').filter({has:admin.page.locator('summary').filter({hasText:/^Coordenação$/})});
        await item.locator('summary').click();
        await item.getByRole('button',{name:'Editar acessos: Coordenação'}).click();
        const dialog=admin.page.getByRole('dialog',{name:'Editar acessos: Coordenação'});
        const reportOptions=dialog.getByRole('group',{name:'Relatórios',exact:true});
        assert.equal(await reportOptions.getByRole('checkbox',{name:'Alunos',exact:true}).isChecked(),true);
        await reportOptions.getByRole('checkbox',{name:'Alunos',exact:true}).uncheck();
        await reportOptions.getByRole('checkbox',{name:'Lista de assinaturas',exact:true}).check();
        const [response]=await Promise.all([
            admin.page.waitForResponse(res=>res.request().method()==='POST'&&res.url().includes('/perfis/menus')),
            dialog.getByRole('button',{name:'Salvar alterações'}).click()
        ]);
        assert.equal(response.status(),200);
        assert.deepEqual(response.request().postDataJSON().reports,['assinaturas']);
        assert.equal((await rosterOnly.context.request.get(own.url)).status(),403);
        await rosterOnly.page.goto(reportUrl(null));
        assert.deepEqual(await rosterOnly.page.locator('.erp-report-menu a').allTextContents(),['Lista de assinaturas']);
        console.log('Permissões independentes por relatório e perfil, persistência via formulário e revogação imediata do PDF.');
    } finally {
        await browser.close();
    }
}
main().catch(e=>{console.error(e);process.exitCode=1});
