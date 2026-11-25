const assert = require('node:assert/strict');
const {readFileSync, writeFileSync} = require('node:fs');
const {join} = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const dir = process.env.WP_QA_DIR;
const fixture = JSON.parse(readFileSync(join(dir,'fixture.json'),'utf8'));
const origin = new URL(fixture.portal).origin;
const downloadFile = join(dir,'lista.pdf');
function url(period,group) {
    const result = new URL(fixture.portal);
    result.searchParams.set('relatorio_periodo',period);
    result.searchParams.set('relatorio_turma',group);
    return result.href;
}
async function main(){
    const browser = await chromium.launch({headless:true});
    try {
        const staff = await browser.newContext({viewport:{width:390,height:844}});
        await staff.addCookies([{...fixture.staff,url:origin}]);
        const page = await staff.newPage();
        await page.goto(fixture.portal);
        await page.getByRole('heading',{name:'Lista de assinaturas'}).waitFor();
        assert.equal(await page.locator('select[name=relatorio_periodo]').inputValue(),String(fixture.period));
        assert.equal(await page.getByRole('link',{name:'Salvar em PDF'}).count(),0);
        await page.locator('select[name=relatorio_turma]').selectOption(String(fixture.class));
        await Promise.all([page.waitForURL(url(fixture.period,fixture.class)),page.getByRole('button',{name:'Ver na tela'}).click()]);
        const sheet = page.getByRole('article',{name:'Prévia da lista de assinaturas'});
        await sheet.waitFor();
        const cells = sheet.locator('tbody');
        assert.equal(await cells.locator('tr').count(),42);
        assert.equal(await cells.locator('tr').first().locator('td').first().textContent(),'0001');
        assert.equal(await cells.locator('tr').last().locator('td').first().textContent(),'0042');
        assert.equal(await sheet.locator('.erp-signature-line').count(),42);
        assert.match(await sheet.textContent(),/CONCEIÇÃO/);
        assert.match(await sheet.textContent(),/____ \/ ____ \/ ________/);
        await page.waitForFunction(()=>[...document.querySelectorAll('.erp-signature-logo img')].some(img=>img.complete&&img.naturalWidth>0));
        const background = await cells.locator('tr td:first-child').evaluateAll(list=>list.slice(0,2).map(cell=>getComputedStyle(cell).backgroundColor));
        assert.deepEqual(background,['rgb(247, 247, 247)','rgb(255, 255, 255)']);
        assert.equal(await sheet.evaluate(el=>el.scrollWidth<=el.clientWidth+2),true);
        await page.setViewportSize({width:1200,height:900});
        assert.equal(await sheet.evaluate(el=>el.scrollWidth<=el.clientWidth+2),true);
        console.log('Prévia responsiva, logo, ordem alfabética, zeros no RA, data e linhas alternadas.');

        const link = page.getByRole('link',{name:'Salvar em PDF'});
        const pdfUrl = new URL(await link.getAttribute('href'),origin).href;
        const pdfResponse = await staff.request.get(pdfUrl);
        assert.equal(pdfResponse.status(),200);
        assert.equal(pdfResponse.headers()['content-type'],'application/pdf');
        assert.match(pdfResponse.headers()['content-disposition'],/^attachment; filename="lista-assinaturas-2027-.+\.pdf"/);
        const pdf = await pdfResponse.body();
        assert.equal(pdf.subarray(0,5).toString(),'%PDF-');
        writeFileSync(downloadFile,pdf);
        const info = execFileSync('pdfinfo',[downloadFile],{encoding:'utf8'});
        assert.match(info,/Pages:\s+2\b/);
        assert.match(info,/Page size:.*\(A4\)/);
        const data = execFileSync('pdftotext',['-layout',downloadFile,'-'],{encoding:'utf8'});
        const pages = data.split('\f').filter(p=>p.trim());
        assert.equal(pages.length,2);
        for(const p of pages){
            assert.match(p,/Período Letivo.*Turma.*Data/);
            assert.match(p,/^\s*RA\s+Nome\s*$/m);
        }
        for(let i=1;i<=42;i++){
            const ra = String(i).padStart(4,'0');
            assert.equal(data.match(new RegExp('^\\s*'+ra+'\\s+','gm'))?.length,1,ra);
        }
        assert.match(data,/CONCEIÇÃO/);
        const images = execFileSync('pdfimages',['-list',downloadFile],{encoding:'utf8'});
        assert.match(images,/^\s*1\s+\d+\s+image\s/m);
        assert.match(images,/^\s*2\s+\d+\s+image\s/m);
        console.log('Download em A4, as duas páginas com logo e cabeçalho, 42 alunos e acentos.');

        const parent = await browser.newContext();
        await parent.addCookies([{...fixture.parent,url:origin}]);
        assert.equal((await parent.request.get(pdfUrl)).status(),403);
        const familyReport = await parent.request.get(url(fixture.period,fixture.class));
        assert.doesNotMatch(await familyReport.text(),/0001.*ÁLUNO TESTE/);
        const anonymous = await browser.newContext();
        assert.equal((await anonymous.request.get(pdfUrl)).status(),403);
        const invalid = new URL(pdfUrl);
        invalid.searchParams.set('relatorio_turma',fixture.otherClass);
        assert.equal((await staff.request.get(invalid.href)).status(),403);
        const otherCompany = await staff.request.get(url(fixture.other,fixture.otherClass));
        assert.doesNotMatch(await otherCompany.text(),/ALUNA OUTRA COLIGADA/);
        console.log('Acesso da secretaria e bloqueio por perfil, vínculo e coligada.');

        await Promise.all([page.waitForURL(/relatorio_turma=$/),page.locator('select[name=relatorio_periodo]').selectOption(String(fixture.previous))]);
        assert.equal(await page.locator('select[name=relatorio_turma] option').count(),2);
        await page.locator('select[name=relatorio_turma]').selectOption(String(fixture.oldClass));
        await Promise.all([page.waitForURL(url(fixture.previous,fixture.oldClass)),page.getByRole('button',{name:'Ver na tela'}).click()]);
        assert.match(await page.locator('.erp-signature-sheet').textContent(),/ALUNA ANO ANTERIOR/);
        await page.goto(url(fixture.period,fixture.emptyClass));
        assert.match(await page.locator('.erp-signature-sheet').textContent(),/Nenhum aluno nesta turma/);
        console.log('Troca de período, matrícula concluída no histórico e turma vazia.');
    } finally {
        await browser.close();
    }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
