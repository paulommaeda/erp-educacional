(() => {
  'use strict';
  let sequence = 0;
  const instances = new WeakMap();
  const element = (tag, text, cls) => { const node = document.createElement(tag); if (text) node.textContent = text; if (cls) node.className = cls; return node; };
  function prepare(form, options = {}) {
    if (!form.isConnected || form.closest('dialog') || instances.has(form)) return instances.get(form);
    const parent = form.parentElement;
    if(form.dataset.modalTransient==='1')parent.classList.add('erp-modal-transient');
    const heading = parent.querySelector(':scope > h2, :scope > h3, :scope > summary');
    const title = options.title || form.dataset.modalTitle || heading?.textContent || 'Cadastro';
    const launch = element('button', options.label || form.dataset.modalLabel || title, 'erp-modal-trigger');
    launch.type = 'button'; launch.setAttribute('aria-haspopup', 'dialog');
    const dialog = element('dialog', null, 'erp-form-dialog');
    const header = element('header', null, 'erp-modal-header');
    const name = element('h2', title); name.id = 'erp-modal-title-' + (++sequence);
    dialog.setAttribute('aria-labelledby', name.id);dialog.id = 'erp-form-modal-' + sequence;
    launch.setAttribute('aria-controls', dialog.id);
    const close = element('button', 'Fechar', 'erp-secondary');close.type = 'button';
    const body = element('div', null, 'erp-modal-body');
    const notice = element('p', '', 'erp-feedback'); notice.setAttribute('role', 'status');
    form.before(launch, notice, dialog);body.append(form);header.append(name, close);dialog.append(header, body);
    let opener = launch;
    const busy = () => form.dataset.saving === '1' || form.dataset.uploading === '1';
    function open() { opener = document.activeElement;dialog.showModal();name.tabIndex=-1;name.focus(); }
    function dismiss() { if (!busy()) dialog.close(); }
    launch.onclick = open;close.onclick = dismiss;
    dialog.addEventListener('cancel', e => { if (busy()) e.preventDefault(); });
    dialog.addEventListener('close', () => { if (dialog.dataset.media === 'open') return;if (opener?.isConnected) opener.focus(); });
    const instance = {dialog,launch,open,success(text = 'Salvo com sucesso.') { notice.textContent=text;dialog.close(); }};
    instances.set(form, instance);
    if (options.autoOpen || form.dataset.modalAuto === '1') open();
    return instance;
  }
  window.EDERPModal = {
    prepare,
    defer(form, options) { queueMicrotask(() => prepare(form, options)); },
    success(form, text) { instances.get(form)?.success(text); }
  };
})();
