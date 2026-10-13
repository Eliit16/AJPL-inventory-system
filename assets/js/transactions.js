document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('transactionRows');
    const addButton = document.getElementById('addLineItem');
    const itemCount = document.getElementById('transactionItemCount');
    const unitCount = document.getElementById('transactionUnitCount');
    const total = document.getElementById('transactionTotal');
    if (!container) return;

    function updateRow(row) {
        const select = row.querySelector('.transaction-product-select');
        const option = select.options[select.selectedIndex];
        const summary = row.querySelector('.transaction-product-summary');
        const qty = row.querySelector('.transaction-qty');
        const price = row.querySelector('.transaction-price');
        const lineTotal = row.querySelector('.transaction-line-total strong');
        const lineStock = row.querySelector('.transaction-line-total span');

        if (!option || !option.value) {
            summary.innerHTML = '<strong>Select item</strong><span>Choose a product</span>';
            lineStock.textContent = '0 piece on hand';
        } else {
            summary.innerHTML = `<strong>${option.dataset.name}</strong><span>${option.dataset.stock} ${option.dataset.unit} on hand</span>`;
            lineStock.textContent = `${option.dataset.stock} ${option.dataset.unit} on hand`;
            if (document.activeElement !== price) price.value = Number(option.dataset.price || 0).toFixed(2);
        }
        const lineValue = (Number(qty.value) || 0) * (Number(price.value) || 0);
        lineTotal.textContent = `₱${lineValue.toFixed(2)}`;
        updateSummary();
    }

    function updateSummary() {
        let items = 0;
        let units = 0;
        let value = 0;
        container.querySelectorAll('.transaction-row').forEach((row) => {
            const qty = Number(row.querySelector('.transaction-qty').value) || 0;
            const price = Number(row.querySelector('.transaction-price').value) || 0;
            if (row.querySelector('.transaction-product-select').value && qty > 0) items++;
            units += qty;
            value += qty * price;
        });
        itemCount.textContent = items;
        unitCount.textContent = units;
        total.textContent = `₱${value.toFixed(2)}`;
    }

    function bindRow(row) {
        row.querySelector('.transaction-product-select').addEventListener('change', () => updateRow(row));
        row.querySelector('.transaction-qty').addEventListener('input', () => updateRow(row));
        row.querySelector('.transaction-price').addEventListener('input', () => updateRow(row));
        row.querySelector('.transaction-product-clear').addEventListener('click', () => {
            row.querySelector('.transaction-product-select').value = '';
            updateRow(row);
        });
        row.querySelector('.remove-row').addEventListener('click', () => {
            if (container.querySelectorAll('.transaction-row').length > 1) {
                row.remove();
                updateSummary();
            }
        });
        updateRow(row);
    }

    bindRow(container.firstElementChild);
    addButton?.addEventListener('click', function () {
        const row = container.firstElementChild.cloneNode(true);
        row.querySelectorAll('input').forEach((input) => {
            input.value = input.classList.contains('transaction-price') ? '0.00' : '0';
        });
        row.querySelector('select').value = '';
        row.querySelector('.transaction-row-head span').textContent = `ITEM ${container.children.length + 1}`;
        container.appendChild(row);
        bindRow(row);
    });
});
