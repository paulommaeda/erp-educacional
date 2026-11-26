(() => {
  'use strict';
  const root = document.querySelector('[data-erp-scholarship-approval]');
  if (!root) return;

  const feedback = root.querySelector('[data-status]');
  const token = location.hash.slice(1);
  if (!/^[1-9]\d{0,17}\.[a-f0-9]{64}$/.test(token)) {
    feedback.textContent = 'Abra o link completo recebido por e-mail para consultar o requerimento.';
    return;
  }

  function element(tag, text = '', className = '') {
    const node = document.createElement(tag);
    node.textContent = text;
    if (className) node.className = className;
    return node;
  }

  function field(label, input) {
    const node = element('label', '', 'erp-admission-field');
    node.append(element('span', label), input);
    return node;
  }

  async function post(endpoint, data = {}) {
    const response = await fetch(endpoint, {
      method: 'POST', credentials: 'omit', cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, ...data }),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || 'Não foi possível acessar o requerimento.');
    return result;
  }

  const formatPercent = value => String(value).replace('.', ',') + '%';
  function show(data) {
    feedback.textContent = '';
    const summary = element('section', '', 'erp-admission-review-card');
    const details = element('dl', '', 'erp-scholarship-details');
    const student = data.aluno_vinculado;
    const fields = [
      ['Protocolo', data.protocolo],
      ['Aluno informado', data.aluno],
      ['Aluno vinculado', student ? student.nome + ' · RA ' + student.ra : 'Aguardando vínculo no ERP'],
      ['Nível, turma e turno', [data.nivel, data.turma, data.turno].filter(Boolean).join(' · ')],
      ['Solicitante', data.solicitante],
      ['Desconto solicitado', formatPercent(data.percentual)],
      ['Renda informada da mãe', data.renda_mae],
      ['Renda informada do pai', data.renda_pai],
      ['Justificativa', data.justificativa],
    ];
    for (const [label, value] of fields) {
      const row = element('div');
      row.append(element('dt', label), element('dd', value, 'erp-scholarship-note'));
      details.append(row);
    }
    summary.append(element('h2', 'Resumo do requerimento'), details);

    const form = element('form', '', 'erp-admission-form erp-admission-review-card erp-scholarship-decision');
    const decision = element('select');
    const approve = new Option('Aprovar', 'aprovado');
    approve.disabled = !student;
    decision.append(new Option('Selecione', ''), approve, new Option('Rejeitar', 'rejeitado'));
    decision.required = true;
    const percent = element('input');
    percent.inputMode = 'decimal';
    percent.maxLength = 12;
    percent.value = data.percentual.replace('.', ',');
    const amount = field('Percentual a aprovar', percent);
    amount.hidden = true;
    decision.addEventListener('change', () => {
      const approving = decision.value === 'aprovado';
      amount.hidden = !approving;
      percent.required = approving;
    });
    const note = element('textarea');
    note.maxLength = 2000;
    note.rows = 3;
    const submit = element('button', 'Registrar decisão');
    submit.type = 'submit';
    const status = element('p');
    status.setAttribute('role', 'status');
    form.append(element('h2', 'Decisão'));
    if (!student) form.append(element('p', 'A aprovação ficará disponível quando o Comercial vincular o aluno. Abra o link novamente após o vínculo.'));
    form.append(field('Resultado', decision), amount, field('Mensagem ao solicitante (opcional)', note), submit, status);
    root.append(summary, form);

    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submit.disabled) return;
      submit.disabled = true;
      status.textContent = 'Registrando decisão…';
      try {
        const result = await post(root.dataset.decisionUrl, { decisao: {
          situacao: decision.value, percentual: percent.value, observacao: note.value, versao: data.versao,
        } });
        summary.remove();
        form.remove();
        history.replaceState(null, '', location.pathname + location.search);
        feedback.textContent = result.situacao === 'aprovado'
          ? 'Requerimento aprovado e bolsa de ' + formatPercent(result.percentual) + ' cadastrada.'
          : 'Requerimento rejeitado.';
        feedback.tabIndex = -1;
        feedback.focus();
      } catch (error) {
        status.textContent = error.message;
        submit.disabled = false;
      }
    });
  }

  post(root.dataset.url).then(show).catch(error => { feedback.textContent = error.message; });
})();
