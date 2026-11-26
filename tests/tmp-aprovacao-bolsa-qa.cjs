const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const fixture = JSON.parse(fs.readFileSync(path.join(path.dirname(process.env.WP_QA_DIR), 'approval-fixture.json')));
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const response = await page.goto(fixture.url);
    assert.match(response.headers()['cache-control'], /no-cache|no-store/);
    await page.getByRole('heading', { name: 'Resumo do requerimento' }).waitFor();
    await page.getByText('Justificativa do teste no navegador.', { exact: true }).waitFor();
    await page.getByText('11,00%', { exact: true }).waitFor();
    assert.equal((await context.cookies()).filter(cookie => cookie.name.startsWith('wordpress_logged_in')).length, 0);
    await page.getByLabel('Resultado').selectOption('aprovado');
    await page.getByLabel('Percentual a aprovar').fill('23,50');
    await page.getByRole('button', { name: 'Registrar decisão' }).click();
    await page.getByText('Requerimento aprovado e bolsa de 23,50% cadastrada.', { exact: true }).waitFor();
    assert.equal(new URL(page.url()).hash, '');
    assert.equal((await context.cookies()).filter(cookie => cookie.name.startsWith('wordpress_logged_in')).length, 0);
    const reopened = await context.newPage();
    await reopened.goto(fixture.url);
    await reopened.getByText('Este link de aprovação é inválido, expirou ou o requerimento já foi decidido.', { exact: true }).waitFor();
    console.log('PASS: resumo, decisão e rejeição de reuso no navegador sem sessão.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
