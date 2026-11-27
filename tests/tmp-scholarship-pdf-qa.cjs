const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const qa = process.env.EDERP_PDF_QA_DIR;
  const fixture = JSON.parse(fs.readFileSync(path.join(qa, 'fixture.json')));
  const browser = await chromium.launch({ channel: process.env.EDERP_PDF_BROWSER_CHANNEL, headless: true });
  try {
    async function open(login) {
      const context = await browser.newContext();
      const page = await context.newPage();
      await page.goto(fixture.portal);
      await page.locator('input[name=login]').fill(login);
      await page.locator('input[name=password]').fill('Senha-fixture-4029');
      await Promise.all([
        page.waitForURL(url => url.searchParams.get('erp_tela') === 'inicio'),
        page.locator('.erp-login-form button[type=submit]').click(),
      ]);
      return { context, page };
    }

    const { context, page } = await open('comercial_pdf');
    await page.goto(fixture.portal);
    let shortUrl;
    let longUrl;
    for (const name of ['short', 'long']) {
      const item = page.locator('article').filter({ hasText: fixture[name] });
      await item.getByRole('button', { name: 'Abrir requerimento' }).click();
      const dialog = page.getByRole('dialog', { name: 'Requerimento ' + fixture[name] });
      const link = dialog.getByRole('link', { name: 'Imprimir / salvar em PDF' });
      await link.waitFor();
      assert.equal(await link.getAttribute('target'), '_blank');
      const href = await link.getAttribute('href');
      if (name === 'short') shortUrl = href;
      else longUrl = href;
      const response = await context.request.get(href);
      assert.equal(response.status(), 200);
      const headers = response.headers();
      assert.equal(headers['content-type'], 'application/pdf');
      assert.match(headers['content-disposition'], new RegExp('^inline;.*' + fixture[name] + '\\.pdf'));
      assert.match(headers['cache-control'], /private.*no-store/);
      fs.writeFileSync(path.join(qa, name + '.pdf'), await response.body());
      assert.equal(await dialog.getByRole('button', { name: 'Registrar decisão' }).count(), 0);
      await dialog.getByRole('button', { name: 'Fechar' }).click();
    }
    const swapped = new URL(shortUrl);
    swapped.searchParams.set('requerimento_bolsa_pdf', new URL(longUrl).searchParams.get('requerimento_bolsa_pdf'));
    assert.equal((await context.request.get(swapped.href)).status(), 403);
    const switched = await page.evaluate(async company => {
      const endpoint = new URL(EDERP.root);
      if (endpoint.searchParams.has('rest_route')) {
        endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route') + 'coligadas/selecionar');
      } else {
        endpoint.pathname += 'coligadas/selecionar';
      }
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EDERP.nonce },
        body: JSON.stringify({ codcoligada: company }),
      });
      return response.status;
    }, fixture.company);
    assert.equal(switched, 200);
    assert.equal((await context.request.get(shortUrl)).status(), 403);
    const anonymous = await browser.newContext();
    assert.equal((await anonymous.request.get(shortUrl)).status(), 403);
    const unauthorized = await open('leitor_pdf');
    const forbidden = await unauthorized.context.request.get(shortUrl);
    assert.equal(forbidden.status(), 403);
    assert.match(await forbidden.text(), /perfil/);
    console.log('PASS: perfil Comercial, link em nova aba, PDF privado por requerimento, sessão anônima e perfil sem permissão bloqueados.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
