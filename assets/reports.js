document.querySelectorAll('[data-erp-report-filters]').forEach(form => {
    const period = form.elements.namedItem('relatorio_periodo');
    const classSelect = form.elements.namedItem('relatorio_turma');
    const download = form.querySelector('[data-erp-report-download]');
    const statusCount = form.querySelector('[data-erp-report-status-count]');
    const year = form.elements.namedItem('relatorio_ano');
    form.addEventListener('change', () => {
        if (download) download.hidden = true;
        if (statusCount) {
            const selected = form.querySelectorAll('input[name="relatorio_situacao[]"]:checked').length;
            statusCount.textContent = selected ? selected + ' selecionada(s)' : 'Todas';
        }
    });
    period?.addEventListener('change', () => {
        if (classSelect.value !== 'todas') classSelect.value = '';
        form.requestSubmit();
    });
    year?.addEventListener('change', () => {
        form.elements.namedItem('relatorio_responsavel').value = '';
        form.requestSubmit();
    });
});
