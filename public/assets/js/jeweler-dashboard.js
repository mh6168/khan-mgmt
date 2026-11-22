const reservationStatusLabels = {
    reserved_for_48_hours: 'Reserved – Awaiting Pickup',
    handed_to_user: 'Collected by Customer',
    refunded_deposit: 'Deposit Refunded',
    user_didnt_pick_up: 'Not Collected – 48 Hours Expired',
};
const reservationStatusClasses = {
    reserved_for_48_hours: 'reserved',
    handed_to_user: 'handed',
    refunded_deposit: 'refunded',
    user_didnt_pick_up: 'expired',
};

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-reservation-status-form]')) {
        return;
    }

    event.preventDefault();
    const submitButton = form.querySelector('[data-reservation-status-save]');
    const message = form.querySelector('[data-reservation-status-message]');
    const select = form.querySelector('[data-reservation-status]');
    const card = form.closest('[data-reservation-card]');
    const badge = card?.querySelector('.reservation-status');

    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Saving...';
    }
    if (message) {
        message.textContent = '';
        message.className = 'reservation-status-message';
    }

    void (async () => {
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
                credentials: 'same-origin',
            });

            const contentType = response.headers.get('content-type') || '';
            let result;
            if (contentType.includes('application/json')) {
                result = await response.json();
            } else {
                const text = await response.text();
                try {
                    result = JSON.parse(text);
                } catch {
                    throw new Error('The server did not return a valid JSON response.');
                }
            }

            if (!response.ok || result?.success !== true) {
                throw new Error(result?.message || 'The reservation status could not be updated.');
            }

            if (badge) {
                badge.textContent = reservationStatusLabels[result.status] || 'Status unavailable';
                badge.className = `reservation-status ${reservationStatusClasses[result.status] || 'unknown'}`;
            }
            if (select) select.disabled = result.status === 'user_didnt_pick_up';
            if (submitButton) {
                submitButton.disabled = result.status === 'user_didnt_pick_up';
                submitButton.textContent = 'Save status';
            }
            if (message) {
                message.textContent = 'Reservation status updated successfully.';
                message.classList.add('success');
            }
        } catch (error) {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Save status';
            }
            if (message) {
                message.textContent = error instanceof Error ? error.message : 'The reservation status could not be updated.';
                message.classList.add('error');
            }
        }
    })();
});

const goldPrices = window.khanGoldPrices || {};
    const productDialog = document.getElementById('product-dialog');
    const typeInput = document.getElementById('product-type-input');
    const typeButtons = [...document.querySelectorAll('[data-product-type]')];
    const productFieldsets = [...document.querySelectorAll('[data-product-fields]')];

    function setProductType(type) {
        typeInput.value = type;
        typeButtons.forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.productType === type));
        });
        productFieldsets.forEach((fieldset) => {
            const active = fieldset.dataset.productFields === type;
            fieldset.hidden = !active;
            fieldset.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !active;
            });
        });
        updateJewelryPrice();
    }

    function updateJewelryPrice() {
        const karat = Number(document.getElementById('jewelry-karat')?.value || 0);
        const weight = Number(document.getElementById('jewelry-gold-weight')?.value || 0);
        const currentPremium = Number(document.getElementById('jewelry-current-premium')?.value || 0);
        const originalPremiumField = document.getElementById('jewelry-original-premium');
        const originalPremium = originalPremiumField?.value === '' ? null : Number(originalPremiumField?.value);
        const currentOutput = document.getElementById('jewelry-current-price');
        const originalOutput = document.getElementById('jewelry-original-price');
        const discountOutput = document.getElementById('jewelry-discount');
        const warning = document.getElementById('jewelry-price-warning');
        const goldPerGram = goldPrices[karat];

        discountOutput.hidden = true;
        originalOutput.textContent = '';
        if (!Number.isFinite(goldPerGram) || goldPerGram <= 0) {
            currentOutput.textContent = 'Price unavailable';
            warning.hidden = false;
            return;
        }
        warning.hidden = true;
        if (weight <= 0) {
            currentOutput.textContent = 'Enter gold weight';
            return;
        }

        const currentPrice = weight * (goldPerGram + currentPremium);
        currentOutput.textContent = `${currentPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP`;
        if (originalPremium !== null && Number.isFinite(originalPremium)) {
            const originalPrice = weight * (goldPerGram + originalPremium);
            originalOutput.textContent = `Original price: ${originalPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP`;
            if (originalPremium > currentPremium && originalPrice > 0) {
                const discountPerGram = originalPremium - currentPremium;
                const discountAmount = originalPrice - currentPrice;
                const discountPercent = (discountAmount / originalPrice) * 100;
                discountOutput.textContent = `Save ${discountPerGram.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP / g · ${discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP total (${discountPercent.toFixed(2)}%)`;
                discountOutput.hidden = false;
            }
        }
    }

    document.querySelectorAll('[data-open-product]').forEach((button) => button.addEventListener('click', () => productDialog.showModal()));
    document.querySelectorAll('[data-close-product]').forEach((button) => button.addEventListener('click', () => productDialog.close()));
    typeButtons.forEach((button) => button.addEventListener('click', () => setProductType(button.dataset.productType)));
    productDialog?.addEventListener('click', (event) => {
        if (event.target === productDialog) productDialog.close();
    });
    document.querySelectorAll('#jewelry-karat, #jewelry-gold-weight, #jewelry-current-premium, #jewelry-original-premium').forEach((input) => input.addEventListener('input', updateJewelryPrice));
    const productForm = document.querySelector('.product-form');
    const productSubmitButton = productForm?.querySelector('button[type="submit"]');
    productForm?.addEventListener('submit', (event) => {
        if (productForm.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }

        productForm.dataset.submitting = 'true';
        if (productSubmitButton) {
            productSubmitButton.disabled = true;
            productSubmitButton.textContent = 'Saving...';
        }
    });
    document.querySelectorAll('[data-delete-form]').forEach((form) => form.addEventListener('submit', (event) => {
        if (!window.confirm('Delete this product and its uploaded photos? This cannot be undone.')) event.preventDefault();
    }));

    const searchInput = document.getElementById('product-search');
    const typeFilter = document.getElementById('type-filter');
    const availabilityFilter = document.getElementById('availability-filter');
    const cards = [...document.querySelectorAll('[data-product-card]')];
    function filterProducts() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        let visibleCount = 0;
        cards.forEach((card) => {
            const visible = (typeFilter.value === 'all' || card.dataset.type === typeFilter.value)
                && (availabilityFilter.value === 'all' || card.dataset.availability === availabilityFilter.value)
                && card.dataset.search.includes(query);
            card.hidden = !visible;
            if (visible) visibleCount++;
        });
        const empty = document.getElementById('filter-empty');
        if (empty) empty.hidden = visibleCount > 0;
    }
    [searchInput, typeFilter, availabilityFilter].forEach((input) => input?.addEventListener('input', filterProducts));
    setProductType(typeInput.value);
    updateJewelryPrice();

