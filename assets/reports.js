document.querySelectorAll('[data-erp-report-filters]').forEach(form => {
    const period = form.elements.namedItem('relatorio_periodo');
    const classSelect = form.elements.namedItem('relatorio_turma');
    period.addEventListener('change', () => {
        classSelect.value = '';
        form.requestSubmit();
    });
});
