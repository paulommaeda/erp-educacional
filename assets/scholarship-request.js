(() => {
  'use strict';
  const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  const key = () => Array.from(crypto.getRandomValues(new Uint8Array(20)), b => b.toString(16).padStart(2, '0')).join('');

  async function call(url, options = {}) {
    const response = await fetch(url, { cache: 'no-store', credentials: 'omit', ...options });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || 'Não foi possível concluir o requerimento.');
    return result;
  }

  function buildField(field) {
    if (field.type === 'radio') {
      const group = element('fieldset', '', 'erp-admission-multiple');
      group.append(element('legend', field.label + (field.required ? ' *' : '')));
      const controls = field.options.map(option => {
        const input = element('input');
        input.type = 'radio'; input.name = field.key; input.value = option; input.required = field.required;
        const label = element('label');
        label.append(input, element('span', option));
        group.append(label);
        return input;
      });
      return { field, node: group, controls };
    }
    const label = element('label', '', 'erp-admission-field');
    const input = element(field.type === 'textarea' ? 'textarea' : 'input');
    if (field.type !== 'textarea') input.type = field.type;
    input.name = field.key;
    input.required = field.required;
    if (field.type === 'number') { input.min = 0; input.max = 999; input.step = 1; }
    else if (field.type === 'text' || field.type === 'textarea') input.maxLength = field.type === 'textarea' ? 8000 : 191;
    if (field.key === 'percentual') input.inputMode = 'decimal';
    label.append(element('span', field.label + (field.required ? ' *' : '')), input);
    return { field, node: label, controls: [input] };
  }

  async function start(root) {
    const url = root.dataset.url;
    const config = await call(url);
    let token = config.token;
    let step = 0;
    let submission = '';
    let submissionKey = '';
    const header = element('header', '', 'erp-admission-header');
    if (config.logo) {
      const logo = element('img'); logo.src = config.logo; logo.alt = config.colegio; header.append(logo);
    }
    header.append(element('h1', 'Requerimento de desconto'), element('p', 'A escola usará as informações para analisar o pedido de bolsa.'));
    const form = element('form', '', 'erp-admission-form');
    form.noValidate = true;
    const progress = element('p'); progress.setAttribute('role', 'status');
    const trap = element('input'); trap.name = 'website'; trap.tabIndex = -1; trap.autocomplete = 'off';
    trap.className = 'erp-admission-honeypot'; trap.setAttribute('aria-hidden', 'true');
    const sections = config.etapas.map(group => {
      const node = element('fieldset', '', 'erp-admission-group erp-admission-step');
      const grid = element('div', '', 'erp-admission-grid');
      const fields = group.fields.map(buildField);
      for (const field of fields) grid.append(field.node);
      node.append(element('legend', group.title), grid);
      return { node, fields };
    });
    const feedback = element('p', '', 'erp-admission-feedback'); feedback.setAttribute('role', 'alert');
    const navigation = element('div', '', 'erp-admission-navigation');
    const back = element('button', 'Anterior', 'erp-admission-secondary'); back.type = 'button';
    const next = element('button', 'Próximo'); next.type = 'submit';
    navigation.append(back, next);
    form.append(progress, trap, ...sections.map(group => group.node), feedback, navigation);
    root.replaceChildren(header, form);

    function show(focus = false) {
      sections.forEach((group, index) => { group.node.hidden = index !== step; });
      progress.textContent = 'Etapa ' + (step + 1) + ' de ' + sections.length + ' · ' + config.etapas[step].title;
      back.hidden = step === 0;
      next.textContent = step === sections.length - 1 ? 'Enviar requerimento' : 'Próximo';
      if (focus) sections[step].node.querySelector('input:not(:disabled), textarea:not(:disabled)')?.focus();
    }
    function conditions() {
      const data = new FormData(form);
      for (const group of sections) for (const { field, node, controls } of group.fields) {
        if (!field.when) continue;
        const visible = field.when.options.includes(data.get(field.when.key));
        node.hidden = !visible;
        for (const input of controls) input.disabled = !visible;
        if (!visible) data.delete(field.key);
      }
    }
    form.addEventListener('change', conditions);
    back.addEventListener('click', () => { step--; feedback.textContent = ''; show(true); });
    conditions(); show();
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (next.disabled) return;
      feedback.textContent = '';
      const invalid = sections[step].fields.flatMap(field => field.controls).find(input => !input.checkValidity());
      if (invalid) { invalid.reportValidity(); return; }
      if (step < sections.length - 1) { step++; show(true); return; }
      const data = Object.fromEntries(new FormData(form));
      const website = data.website;
      delete data.website;
      const body = JSON.stringify(data);
      if (body !== submission) { submission = body; submissionKey = key(); }
      next.disabled = back.disabled = true;
      next.textContent = 'Enviando…';
      try {
        const result = await call(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': submissionKey },
          body: JSON.stringify({ dados: data, token, website }) });
        const success = element('section', '', 'erp-admission-success'); success.setAttribute('role', 'status');
        success.append(element('h2', 'Requerimento recebido'), element('p', 'Protocolo ' + result.protocolo),
          element('p', 'O resultado da análise será enviado ao e-mail informado.'));
        form.replaceWith(success);
      } catch (error) {
        feedback.textContent = error.message;
        if (error.message.includes('expirou')) {
          try { token = (await call(url)).token; feedback.textContent = 'Formulário renovado. Aguarde alguns segundos e envie novamente.'; }
          catch (refreshError) { feedback.textContent = refreshError.message; }
        }
      } finally { next.disabled = back.disabled = false; show(); }
    });
  }

  for (const root of document.querySelectorAll('[data-erp-scholarship-form]')) {
    start(root).catch(error => root.querySelector('[role=status]').textContent = error.message);
  }
})();
