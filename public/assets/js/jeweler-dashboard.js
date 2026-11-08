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
