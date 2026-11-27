const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM, VirtualConsole} = require('jsdom');

const fixture = require('./fixtures/student-record.json');
const tick = () => new Promise(resolve => setTimeout(resolve, 30));
const students = [
    {idaluno: '1', nome: 'ANA', ra: 'BA-001', ativo: 1, percentual_bolsa_aluno: '10.00'},
    {idaluno: '2', nome: 'BIA', ra: 'BA-002', ativo: 1, percentual_bolsa_aluno: '0.00'}
];
const plan = {idplano: '4', versao: '1', nome: 'Anuidade escolar', valor_anuidade: '18000.00', pontualidade_valor: '103.00'};

function click(root, label) {
    const control = [...root.querySelectorAll('button')].find(b => b.textContent === label);
    assert.ok(control, label);
    control.click();
}

async function boot(screen, query, caps) {
    const markup = screen === 'discounts' ? '<section data-erp-contracts="discounts"></section>' : '<section data-erp-screen="'+screen+'"></section>';
    const errors = [], calls = [], grants = [], vc = new VirtualConsole();
    vc.on('jsdomError', err => { if (!err.message.includes('navigation')) errors.push(err.message); });
    const dom = new JSDOM('<main class="ederp">'+markup+'</main>', {url:'https://example.test/portal?erp_tela='+screen+query, runScripts:'dangerously', virtualConsole:vc});
    const w = dom.window, d = w.document;
    w.HTMLDialogElement.prototype.showModal = function() { this.open = true; };
    w.HTMLDialogElement.prototype.close = function() { this.open = false; this.dispatchEvent(new w.Event('close')); };
    w.EDERP = {...structuredClone(fixture.settings), caps, root:'https://example.test/api/', nonce:'test', today:'2026-11-19'};
    w.fetch = async (raw, options = {}) => {
        const url = new URL(raw), route = url.pathname.slice(5), body = options.body ? JSON.parse(options.body) : null;
        calls.push({url, route, body, headers:options.headers});
        let data;
        if (body && route.startsWith('bolsas-alunos/1')) {
            grants[0] = {...body, idbolsa_aluno:'3', versao:grants.length ? '2' : '1'};
            data = {contratos_atualizados:0, parcelas_alteradas:0, parcelas_preservadas:0};
        } else if (body) data = {total:2};
        else if (route === 'bolsas-alunos') {
            const term = url.searchParams.get('search');
            const page = Number(url.searchParams.get('page'));
            data = term ? {items:term === 'BA-001' ? [students[0]] : [], total:term === 'BA-001' ? 1 : 0}
                : {items:[students[page - 1]], total:21};
        }
        else if (route === 'bolsas-alunos/1') data = {aluno:students[0], items:grants};
        else if (route === 'secretaria/alunos/1') data = {...structuredClone(fixture.sheet), periodos_pendentes:[{idvinculoperiodo:'5', codperiodo:'7', codigo:'2027', descricao:'Ano'}]};
        else if (route === 'secretaria/alunos') data = {items:students, total:2};
        else if (route === 'turmas/9/plano') data = url.searchParams.has('idaluno') ? {...plan, percentual_bolsa_aluno:'10.00'} : plan;
        else if (route === 'configuracoes') data = {periodos:[]};
        else if (route === 'matriculas/filtros') data = {cursos:[], turmas:[], turnos:[]};
        else if (route.startsWith('opcoes/')) data = {items:[{id:route === 'opcoes/turmas' ? '9' : '7', label:'Opção'}], page:1, more:false};
        else data = {items:[], total:0};
        return {ok:true, json:async () => data};
    };
    for (const asset of ['modals', 'person-fields', 'scholarships', 'workflow', 'contracts'])
        w.eval(fs.readFileSync(path.join(__dirname, '../assets/'+asset+'.js'), 'utf8'));
    await tick();
    return {w, d, calls, grants, errors, close() { assert.deepEqual(errors, []); w.close(); }};
}

async function testManagement() {
    const x = await boot('discounts', '', {discounts:true});
    const latest = () => [...x.d.querySelectorAll('dialog[open]')].at(-1);
    click(x.d, 'Bolsas por aluno');
    await tick();
    const picker = latest(), pagination = picker.querySelector('nav');
    assert.match(pagination.textContent, /Página 1 de 2/);
    click(picker, 'Próxima');
    await tick();
    assert.match(pagination.textContent, /Página 2 de 2/);
    assert.ok(picker.querySelector('button[aria-label="BIA · RA BA-002"]'));
    picker.querySelector('input[type=search]').value = 'BA-001';
    picker.querySelector('form').requestSubmit();
    await tick();
    assert.equal(pagination.hidden, true);
    assert.equal(picker.querySelector('[role=status]').textContent, '1 aluno encontrado');
    const student = picker.querySelector('button[aria-label="ANA · RA BA-001"]');
    assert.equal(student.querySelector('.erp-student-search-name').textContent, 'ANA');
    assert.equal(student.querySelector('.erp-student-search-ra').textContent, 'RA BA-001');
    student.click();
    await tick();
    assert.match(latest().textContent, /Nenhuma bolsa cadastrada/);
    click(latest(), 'Cadastrar bolsa do aluno');
    let form = latest().querySelector('form');
    form.querySelector('[aria-label="Título"]').value = 'Bolsa Família';
    form.querySelector('[aria-label="Descrição"]').value = 'Condição para irmãos';
    form.querySelector('[aria-label="Percentual da parcela original (%)"]').value = '10,00';
    form.requestSubmit();
    await tick();
    const created = x.calls.find(c => c.body);
    assert.equal(created.route, 'bolsas-alunos/1');
    assert.deepEqual(created.body, {titulo:'Bolsa Família', descricao:'Condição para irmãos', percentual:'10.00', ativo:'1'});
    assert.ok(created.headers['Idempotency-Key']);
    assert.match(latest().textContent, /Bolsa Família/);
    assert.deepEqual([...latest().querySelectorAll('.erp-record-grid dd')].map(value=>value.textContent),['10,00%','Ativa','Condição para irmãos']);
    click(latest(), 'Editar bolsa do aluno');
    form = latest().querySelector('form');
    form.querySelector('[aria-label="Situação"]').value = '0';
    form.requestSubmit();
    await tick();
    assert.equal(x.calls.find(c => c.route === 'bolsas-alunos/1/3').body.versao, '1');
    assert.deepEqual([...latest().querySelectorAll('.erp-record-grid dd')].map(value=>value.textContent),['10,00%','Inativa','Condição para irmãos']);
    const detail = x.w.EDERPScholarships.contractStudentGrants(x.grants);
    assert.match(detail.textContent, /Bolsa Família.*Condição para irmãos/);
    assert.equal(detail.querySelector('button'), null);
    x.close();

    for (const discounts of [true, false]) {
        const profile = await boot('alunos', '&aluno=1', {people:true, discounts});
        const tab = profile.d.querySelector('[data-tab="bolsas_aluno"]');
        assert.equal(!!tab, discounts);
        if (tab) {
            tab.click();
            await tick();
            assert.match(profile.d.body.textContent, /Cadastrar bolsa do aluno/);
            assert.ok(profile.calls.some(c => c.route === 'bolsas-alunos/1'));
        }
        profile.close();
    }
}

async function testPreview(batch) {
    const x = await boot('matriculas', batch ? '&novo=1' : '&aluno=1&vinculo=5', {academic:true, people:true});
    const form = batch ? x.d.querySelector('[data-batch-enrollment] form') || x.d.querySelector('dialog[open] form') : x.d.querySelector('form');
    assert.ok(form, 'formulário de matrícula');
    const choose = (field, value) => {
        const input = form.querySelector('[name="'+field+'"]');
        input.value = value;
        input.dispatchEvent(new x.w.Event('change'));
    };
    if (batch) choose('codperiodo', '7');
    choose('idcurso', '7');
    choose('idturno', '7');
    await tick();
    choose('idturma', '9');
    await tick();
    if (batch) click(form, 'Selecionar alunos desta página');
    const quotes = [...form.querySelectorAll('.erp-scholarship-quote')].map(el => el.textContent);
    assert.equal(quotes.length, batch ? 2 : 1);
    assert.match(quotes[0], /10,00%.*1\.453,00.*1\.350,00/);
    if (batch) {
        assert.match(quotes[0], /ANA/);
        assert.match(quotes[1], /BIA.*1\.500,00.*1\.397,00/);
    } else assert.equal(x.calls.find(c => c.route === 'turmas/9/plano').url.searchParams.get('idaluno'), '1');
    form.querySelector('[name="primeiro_vencimento"]').value = '2027-01-10';
    form.requestSubmit();
    await tick();
    const post = x.calls.find(c => c.body);
    assert.equal(post.route, batch ? 'matriculas/lote' : 'alunos/1/periodos/5/turma');
    assert.deepEqual(post.body.percentuais_bolsas_alunos, batch ? {'1':'10.00','2':'0.00'} : {'1':'10.00'});
    assert.equal(post.body.bolsas, undefined);
    x.close();
}

(async () => {
    await testManagement();
    await testPreview(false);
    await testPreview(true);
    console.log('STUDENT_SCHOLARSHIPS_UI_OK: cadastro, edição, acesso pela ficha, título e descrição, prévias individual e em lote');
})().catch(error => { console.error(error); process.exit(1); });
