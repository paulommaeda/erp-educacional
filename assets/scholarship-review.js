(() => {
  'use strict';
  const root = document.querySelector('[data-erp-scholarship-review]');
  if (!root) return;
  const title = root.querySelector('h1');
  const path = 'requerimentos-bolsa';
  const labels = { pendente: 'Pendente', aprovado: 'Aprovado', rejeitado: 'Rejeitado' };
  const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  function button(text, action) {
    const node = element('button', text);
    node.type = 'button';
    if (action) node.addEventListener('click', action);
    return node;
  }
  function field(label, control) {
    const node = element('label', label);
    node.append(control);
    return node;
  }
  const date = value => value ? String(value).slice(0, 10).split('-').reverse().join('/') : '—';
  const percent = value => value ? String(value).replace('.', ',') + '%' : '—';
  function url(route, query) {
    const base = new URL(EDERP.root, location.href);
    if (base.searchParams.has('rest_route')) base.searchParams.set('rest_route', base.searchParams.get('rest_route') + route);
    else base.pathname += route;
    for (const [key, value] of Object.entries(query)) base.searchParams.set(key, value);
    return base;
  }
  async function api(route, query = {}, data = null) {
    const headers = { 'X-WP-Nonce': EDERP.nonce };
    const options = { credentials: 'same-origin', cache: 'no-store', headers };
    if (data) {
      options.method = 'POST'; options.body = JSON.stringify(data);
      headers['Content-Type'] = 'application/json';
      headers['Idempotency-Key'] = Array.from(crypto.getRandomValues(new Uint8Array(20)), b => b.toString(16).padStart(2, '0')).join('');
    }
    const response = await fetch(url(route, query), options);
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || 'Não foi possível concluir a operação.');
    return result;
  }
  function dialog(name) {
    const node = element('dialog', '', 'ederp erp-admission-review erp-scholarship-dialog');
    node.setAttribute('aria-label', name);
    const header = element('header', '', 'erp-modal-header');
    const close = button('Fechar', () => node.close());
    const content = element('div');
    const message = element('p', '', 'erp-admission-feedback'); message.setAttribute('role', 'status');
    header.append(element('h2', name), close); node.append(header, content, message);
    node.addEventListener('close', () => node.remove());
    node.addEventListener('cancel', event => { if (close.disabled) event.preventDefault(); });
    document.body.append(node); node.showModal();
    return { node, content, message, close };
  }
  function bind(form, view, label, operation) {
    const submit = button(label); submit.type = 'submit'; form.append(submit);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (view.close.disabled) return;
      view.close.disabled = submit.disabled = true;
      view.message.textContent = 'Processando…';
      try { await operation(); }
      catch (error) { view.message.textContent = error.message; }
      finally { view.close.disabled = submit.disabled = false; }
    });
  }

  const state = element('select');
  for (const [value, text] of [['pendente', 'Pendentes'], ['aprovado', 'Aprovados'], ['rejeitado', 'Rejeitados'], ['todos', 'Todos']]) {
    state.append(new Option(text, value));
  }
  const list = element('div');
  const tools = element('div', '', 'erp-scholarship-actions');
  const pageNumber = element('span');
  pageNumber.setAttribute('aria-live', 'polite');
  const message = element('p'); message.setAttribute('role', 'status');
  let page = 1, sequence = 0, steps = [];
  const previous = button('Anterior', () => { page--; load(); });
  const next = button('Próxima', () => { page++; load(); });
  tools.append(field('Situação', state));
  if (EDERP.caps?.admin) tools.append(button('Configurar formulário público', settings));
  const pagination = element('nav', '', 'erp-scholarship-actions');
  pagination.setAttribute('aria-label', 'Páginas de requerimentos');
  pagination.append(previous, pageNumber, next);
  root.replaceChildren(title, tools, message, list, pagination);
  state.addEventListener('change', () => { page = 1; load(); });

  async function load() {
    const pass = ++sequence;
    previous.disabled = next.disabled = true;
    list.textContent = 'Carregando requerimentos…';
    try {
      const result = await api(path, { status: state.value, page });
      if (pass !== sequence) return;
      steps = result.etapas;
      list.replaceChildren();
      if (!result.items.length) list.append(element('p', 'Nenhum requerimento nesta página.'));
      for (const item of result.items) {
        const card = element('article', '', 'ederp-card erp-scholarship-list-item');
        const info = element('div');
        info.append(element('strong', item.nome_aluno), element('p', item.protocolo + ' · ' + date(item.criado_em) + ' · ' + labels[item.situacao]
          + (item.percentual_aprovado ? ' · ' + percent(item.percentual_aprovado) : '')
          + (item.idaluno ? '' : ' · Sem vínculo')));
        card.append(info, button('Abrir requerimento', () => review(item.idpedido, item.protocolo)));
        list.append(card);
      }
      pageNumber.textContent = 'Página ' + page;
      previous.disabled = page === 1; next.disabled = !result.mais;
    } catch (error) { if (pass === sequence) list.textContent = error.message; }
  }

  async function settings() {
    const view = dialog('Configurar formulário público de bolsas');
    view.message.textContent = 'Carregando…';
    try {
      const data = await api(path + '/configuracoes');
      if (!view.node.isConnected) return;
      view.message.textContent = '';
      const form = element('form', '', 'ederp-form');
      const active = element('input'); active.type = 'checkbox'; active.checked = data.ativo;
      const emails = element('textarea'); emails.value = data.emails.join('\n'); emails.rows = 5; emails.maxLength = 6000;
      const link = element('a', 'Abrir formulário público'); link.href = data.url; link.target = '_blank'; link.rel = 'noopener';
      const address = element('input'); address.value = data.url; address.readOnly = true;
      form.append(field('Receber novos requerimentos', active), field('E-mails da administração, um por linha', emails), field('Link público', address));
      view.content.append(link, form);
      bind(form, view, 'Salvar configurações', async () => {
        await api(path + '/configuracoes', {}, { ativo: active.checked, emails: emails.value });
        view.message.textContent = 'Configurações salvas.';
      });
    } catch (error) { view.message.textContent = error.message; }
  }

  async function review(id, protocol) {
    const view = dialog('Requerimento ' + protocol);
    view.message.textContent = 'Carregando…';
    try {
      const data = await api(path + '/' + id);
      if (!view.node.isConnected) return;
      view.message.textContent = '';
      view.content.append(element('p', labels[data.situacao] + ' · Recebido em ' + date(data.criado_em)));
      for (const step of steps) {
        const group = element('section', '', 'erp-admission-review-card');
        const details = element('dl', '', 'erp-scholarship-details');
        for (const f of step.fields) {
          if (!(f.key in data.dados)) continue;
          const value = data.dados[f.key];
          const row = element('div');
          row.append(element('dt', f.adminLabel || f.label),
            element('dd', f.type === 'date' ? date(value) : f.key === 'percentual' ? percent(value) : value));
          details.append(row);
        }
        group.append(element('h3', step.title), details); view.content.append(group);
      }
      const student = data.aluno;
      view.content.append(element('h3', 'Aluno vinculado'), element('p', student
        ? student.nome + ' · RA ' + student.ra + ' · ' + date(student.data_nascimento) : 'Aguardando vínculo manual.'));
      if (data.situacao === 'pendente') {
        showLink(view, data);
        showDecision(view, data);
      } else {
        if (data.percentual_aprovado) view.content.append(element('p', 'Percentual aprovado · ' + percent(data.percentual_aprovado)));
        if (data.observacao) view.content.append(element('p', data.observacao, 'erp-scholarship-note'));
      }
      const sent = data.notificacoes;
      view.content.append(element('p', 'E-mails · ' + sent.enviado + ' enviados · ' + sent.pendente + ' pendentes · ' + sent.falha + ' com falha'));
    } catch (error) { view.message.textContent = error.message; }
  }

  function showLink(view, data) {
    const box = element('details', '', 'erp-admission-review-card');
    const query = element('input'); query.type = 'search'; query.minLength = 2; query.maxLength = 191; query.required = true;
    const form = element('form', '', 'ederp-form');
    const results = element('div');
    form.append(field('Nome ou RA', query));
    bind(form, view, 'Buscar alunos', async () => {
      const candidates = await api(path + '/' + data.idpedido + '/alunos', { busca: query.value });
      const select = element('select'); select.required = true; select.append(new Option('Selecione', ''));
      for (const student of candidates) select.append(new Option(student.nome + ' · RA ' + student.ra + ' · ' + date(student.data_nascimento), student.idaluno));
      results.replaceChildren(); view.message.textContent = '';
      if (!candidates.length) { results.textContent = 'Nenhum aluno encontrado nesta coligada.'; return; }
      const link = element('form', '', 'ederp-form'); link.append(field('Aluno encontrado', select));
      bind(link, view, 'Confirmar vínculo', async () => {
        await api(path + '/' + data.idpedido + '/vincular', {}, { idaluno: select.value, versao: data.versao });
        view.node.close(); await load(); await review(data.idpedido, data.protocolo);
      });
      results.append(link);
    });
    box.append(element('summary', data.aluno ? 'Alterar vínculo' : 'Vincular aluno manualmente'), form, results);
    view.content.append(box);
  }

  function showDecision(view, data) {
    const form = element('form', '', 'ederp-form erp-admission-review-card');
    const status = element('select'); status.required = true;
    status.append(new Option('Selecione', ''), new Option('Aprovar', 'aprovado'), new Option('Rejeitar', 'rejeitado'));
    const amount = element('input'); amount.inputMode = 'decimal'; amount.value = data.dados.percentual.replace('.', ',');
    const amountField = field('Percentual a aprovar', amount); amountField.hidden = true;
    const note = element('textarea'); note.maxLength = 2000; note.rows = 3;
    status.addEventListener('change', () => {
      amountField.hidden = status.value !== 'aprovado';
      amount.required = status.value === 'aprovado';
    });
    form.append(element('h3', 'Decisão'), field('Resultado', status), amountField, field('Mensagem ao solicitante (opcional)', note));
    bind(form, view, 'Registrar decisão', async () => {
      const result = await api(path + '/' + data.idpedido + '/decidir', {}, { versao: data.versao,
        situacao: status.value, percentual: amount.value, observacao: note.value });
      message.textContent = result.bolsa ? 'Requerimento aprovado e bolsa cadastrada.' : 'Requerimento rejeitado.';
      view.node.close(); await load(); await review(data.idpedido, data.protocolo);
    });
    view.content.append(form);
  }

  load();
})();
