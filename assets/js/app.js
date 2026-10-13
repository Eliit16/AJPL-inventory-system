document.addEventListener('DOMContentLoaded', function () {
    const flash = document.querySelector('[data-flash]');
    if (flash) {
        setTimeout(() => {
            flash.classList.add('hide');
        }, 4000);
    }

    document.querySelectorAll('.row-menu-toggle').forEach((button) => {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const menu = button.nextElementSibling;
            if (!menu) return;

            const shouldOpen = !menu.classList.contains('show');
            document.querySelectorAll('.row-menu').forEach((item) => {
                if (item !== menu) item.classList.remove('show');
            });

            if (shouldOpen) {
                menu.classList.add('show');
            }
        });
    });

    document.addEventListener('click', function (event) {
        const clickedToggle = event.target.closest('.row-menu-toggle');
        const clickedMenu = event.target.closest('.row-menu');

        if (!clickedToggle && !clickedMenu) {
            document.querySelectorAll('.row-menu').forEach((menu) => menu.classList.remove('show'));
        }
    });

    const stockModal = document.getElementById('stockMovementModal');
    const stockActionInput = document.getElementById('stockMovementAction');
    const stockProductSelect = document.getElementById('stockMovementProduct');
    const stockProductSummary = document.getElementById('stockProductSummary');
    const stockProductClear = document.getElementById('stockProductClear');
    const stockActionButtons = document.querySelectorAll('.stock-toggle-btn');

    function setStockDirection(direction) {
        const isIn = direction === 'in';
        stockActionButtons.forEach((button) => {
            const active = button.dataset.direction === direction;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (stockActionInput) {
            stockActionInput.value = isIn ? 'stock_in' : 'stock_out';
        }
    }

    function updateStockProductSummary() {
        if (!stockProductSelect || !stockProductSummary) return;
        const option = stockProductSelect.options[stockProductSelect.selectedIndex];
        if (!option || !option.value) {
            stockProductSummary.innerHTML = '<strong>Select product</strong><span>Choose an active product</span>';
            return;
        }
        stockProductSummary.innerHTML = `<strong>${option.dataset.name || option.textContent}</strong><span>${option.dataset.stock || '0'} ${option.dataset.unit || ''} on hand</span>`;
    }

    document.querySelectorAll('.stock-movement-trigger').forEach((button) => {
        button.addEventListener('click', function () {
            const direction = button.dataset.direction || 'in';
            if (stockProductSelect) {
                stockProductSelect.value = button.dataset.productId || '';
                if (stockProductSummary) {
                    stockProductSummary.innerHTML = `<strong>${button.dataset.productName || 'Select product'}</strong><span>${button.dataset.productStock || '0'} ${button.dataset.productUnit || ''} on hand</span>`;
                } else {
                    updateStockProductSummary();
                }
            }
            setStockDirection(direction);
            if (stockModal) {
                stockModal.classList.add('open');
            }
        });
    });

    if (stockProductSelect) {
        stockProductSelect.addEventListener('change', updateStockProductSummary);
    }
    if (stockProductClear) {
        stockProductClear.addEventListener('click', function () {
            if (stockProductSelect) stockProductSelect.value = '';
            updateStockProductSummary();
        });
    }

    stockActionButtons.forEach((button) => {
        button.addEventListener('click', function () {
            setStockDirection(button.dataset.direction || 'in');
        });
    });

    document.addEventListener('click', function (event) {
        if (event.target && event.target.closest('.modal-close')) {
            event.target.closest('.modal')?.classList.remove('open');
        }
    });

    const chartEls = document.querySelectorAll('[data-chart]');
    chartEls.forEach((chartEl) => {
        const type = chartEl.dataset.chart;
        const raw = chartEl.dataset.labels ? JSON.parse(chartEl.dataset.labels) : [];
        const values = chartEl.dataset.values ? JSON.parse(chartEl.dataset.values) : [];
        const received = chartEl.dataset.received ? JSON.parse(chartEl.dataset.received) : null;
        const issued = chartEl.dataset.issued ? JSON.parse(chartEl.dataset.issued) : null;
        if (!raw.length || (!values.length && (!received || !issued))) return;

        const ctx = chartEl.getContext('2d');
        if (!ctx) return;

        new Chart(ctx, {
            type: type,
            data: {
                labels: raw,
                datasets: received && issued ? [
                    { label: 'Received', data: received, backgroundColor: '#83b94d', borderColor: '#fff', borderWidth: 1, borderRadius: 5 },
                    { label: 'Issued', data: issued, backgroundColor: '#b8443e', borderColor: '#fff', borderWidth: 1, borderRadius: 5 }
                ] : [{
                    data: values,
                    backgroundColor: chartEl.dataset.colors ? JSON.parse(chartEl.dataset.colors) : ['#83B94D', '#2F604B', '#A7C978', '#4C8061', '#C2D99A'],
                    borderColor: '#fff',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: type === 'bar' && Boolean(received && issued), position: 'top', align: 'end', labels: { boxWidth: 12, boxHeight: 8 } }
                },
                scales: type === 'bar' ? { y: { beginAtZero: true } } : {}
            }
        });
    });

    document.querySelectorAll('[data-open-modal]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const modal = document.getElementById(trigger.dataset.openModal);
            if (modal) modal.classList.add('open');
        });
    });

    document.querySelectorAll('.modal-close, .modal-backdrop').forEach((node) => {
        node.addEventListener('click', () => {
            node.closest('.modal')?.classList.remove('open');
        });
    });
});
