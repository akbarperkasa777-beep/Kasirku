document.addEventListener('DOMContentLoaded', function () {
    // Confirm dialog for critical actions
    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            if (!window.confirm(element.dataset.confirm || 'Lanjutkan tindakan ini?')) {
                event.preventDefault();
            }
        });
    });

    // Live Clock in Topbar (if exists)
    var clockElement = document.getElementById('topbar-live-clock');
    if (clockElement) {
        function updateClock() {
            var now = new Date();
            var hours = String(now.getHours()).padStart(2, '0');
            var minutes = String(now.getMinutes()).padStart(2, '0');
            var seconds = String(now.getSeconds()).padStart(2, '0');
            clockElement.textContent = hours + ':' + minutes + ':' + seconds + ' WIB';
        }
        updateClock();
        setInterval(updateClock, 1000);
    }

    document.querySelectorAll('[data-generate-barcode]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById('product-barcode');
            if (!input) return;

            var randomValue = window.crypto && window.crypto.getRandomValues
                ? window.crypto.getRandomValues(new Uint32Array(1))[0].toString(36).toUpperCase()
                : Math.random().toString(36).slice(2, 10).toUpperCase();
            input.value = 'AKM' + Date.now().toString(36).toUpperCase() + randomValue;
            input.focus();
            input.select();
        });
    });

    // POS Form Logic
    var posForm = document.getElementById('pos-form');
    if (!posForm) return;

    var discountInput = document.getElementById('discount-input');
    var paymentInput = document.getElementById('payment-input');
    var cart = document.getElementById('cart-items');
    var emptyState = document.getElementById('cart-empty');
    var currentSubtotal = 0;
    var currentTotal = 0;

    function formatRupiah(value) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
    }

    function updateTotals() {
        var items = Array.from(cart.querySelectorAll('.cart-item'));
        var subtotal = 0;
        var totalQty = 0;

        items.forEach(function (item) {
            var quantityInput = item.querySelector('.cart-qty');
            var quantity = Math.max(0, parseInt(quantityInput.value, 10) || 0);
            var price = parseInt(item.dataset.price, 10);
            var stock = parseInt(quantityInput.max, 10);
            if (quantity > stock) {
                quantity = stock;
                quantityInput.value = stock;
            }
            totalQty += quantity;
            var lineTotal = price * quantity;
            subtotal += lineTotal;
            var subtotalEl = item.querySelector('.line-subtotal');
            if (subtotalEl) {
                subtotalEl.textContent = formatRupiah(lineTotal);
            }
        });

        currentSubtotal = subtotal;
        var discount = Math.min(subtotal, Math.max(0, parseInt(discountInput ? discountInput.value : 0, 10) || 0));
        var total = subtotal - discount;
        currentTotal = total;
        var payment = Math.max(0, parseInt(paymentInput ? paymentInput.value : 0, 10) || 0);

        var subtotalEl = document.getElementById('subtotal-label');
        if (subtotalEl) subtotalEl.textContent = formatRupiah(subtotal);

        var discountEl = document.getElementById('discount-label');
        if (discountEl) discountEl.textContent = '-' + formatRupiah(discount);

        var totalEl = document.getElementById('total-label');
        if (totalEl) totalEl.textContent = formatRupiah(total);

        var changeEl = document.getElementById('change-label');
        if (changeEl) {
            var change = Math.max(0, payment - total);
            changeEl.textContent = formatRupiah(change);
            if (payment >= total && total > 0) {
                changeEl.classList.remove('text-muted');
                changeEl.classList.add('text-success');
            } else {
                changeEl.classList.remove('text-success');
                changeEl.classList.add('text-muted');
            }
        }

        var cartCountEl = document.getElementById('cart-count');
        if (cartCountEl) {
            cartCountEl.textContent = totalQty + ' item';
        }

        if (emptyState) {
            emptyState.classList.toggle('d-none', items.length > 0);
        }
    }

    function addProductToCart(product) {
        var id = String(product.id);
        var name = String(product.name);
        var price = parseInt(product.price, 10);
        var stock = parseInt(product.stock, 10);
        var existing = cart.querySelector('.cart-item[data-id="' + id + '"]');

        if (existing) {
            var existingQuantity = existing.querySelector('.cart-qty');
            var currentQuantity = parseInt(existingQuantity.value, 10) || 0;
            if (currentQuantity >= stock) return false;
            existingQuantity.value = currentQuantity + 1;
        } else {
            var item = document.createElement('div');
            item.className = 'cart-line cart-item';
            item.dataset.id = id;
            item.dataset.price = price;

            var info = document.createElement('div');
            info.className = 'cart-item-info';
            var productName = document.createElement('strong');
            productName.textContent = name;
            var detail = document.createElement('small');
            detail.textContent = formatRupiah(price);
            info.appendChild(productName);
            info.appendChild(detail);

            var controls = document.createElement('div');
            controls.className = 'cart-qty-wrapper';

            var quantity = document.createElement('input');
            quantity.type = 'number';
            quantity.className = 'form-control cart-qty';
            quantity.name = 'qty[' + id + ']';
            quantity.min = '0';
            quantity.max = stock;
            quantity.value = '1';
            quantity.setAttribute('aria-label', 'Jumlah ' + name);

            var subtotal = document.createElement('span');
            subtotal.className = 'line-subtotal';
            subtotal.textContent = formatRupiah(price);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove-item';
            remove.setAttribute('aria-label', 'Hapus ' + name);
            remove.innerHTML = '<i class="bi bi-trash3"></i>';

            controls.appendChild(quantity);
            controls.appendChild(subtotal);
            controls.appendChild(remove);
            item.appendChild(info);
            item.appendChild(controls);
            cart.appendChild(item);
        }

        updateTotals();
        return true;
    }

    document.querySelectorAll('[data-add-product]').forEach(function (button) {
        button.addEventListener('click', function () {
            addProductToCart({
                id: button.dataset.id,
                name: button.dataset.name,
                price: button.dataset.price,
                stock: button.dataset.stock
            });
        });
    });

    var barcodeInput = document.getElementById('barcode-input');
    var barcodeStatus = document.getElementById('barcode-status');
    var barcodeSubmit = document.getElementById('barcode-submit');
    var barcodeLookupPending = false;

    function setBarcodeStatus(message, statusClass) {
        if (!barcodeStatus) return;
        barcodeStatus.textContent = message;
        barcodeStatus.classList.remove('text-muted', 'text-success', 'text-danger');
        barcodeStatus.classList.add(statusClass);
    }

    function lookupBarcode() {
        if (!barcodeInput || barcodeLookupPending) return;
        var barcode = barcodeInput.value.trim();
        if (!barcode) return;

        barcodeLookupPending = true;
        setBarcodeStatus('Mencari produk...', 'text-muted');

        fetch('pos.php?barcode_lookup=' + encodeURIComponent(barcode), {
            headers: { Accept: 'application/json' },
            cache: 'no-store'
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) throw new Error(data.message || 'Produk tidak dapat dicari.');
                    return data;
                });
            })
            .then(function (data) {
                if (addProductToCart(data.product)) {
                    setBarcodeStatus('Ditambahkan ke keranjang: ' + data.product.name, 'text-success');
                } else {
                    setBarcodeStatus('Jumlah produk sudah mencapai stok yang tersedia.', 'text-danger');
                }
            })
            .catch(function (error) {
                setBarcodeStatus(error.message || 'Pencarian barcode gagal. Silakan coba kembali.', 'text-danger');
            })
            .finally(function () {
                barcodeLookupPending = false;
                barcodeInput.value = '';
                barcodeInput.focus();
            });
    }

    if (barcodeInput) {
        barcodeInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lookupBarcode();
            }
        });
    }
    if (barcodeSubmit) {
        barcodeSubmit.addEventListener('click', lookupBarcode);
    }

    // Cart Input & Event Delegation
    cart.addEventListener('input', function (event) {
        if (event.target.classList.contains('cart-qty')) {
            updateTotals();
        }
    });

    cart.addEventListener('click', function (event) {
        var removeButton = event.target.closest('.remove-item');
        if (removeButton) {
            var item = removeButton.closest('.cart-item');
            if (item) {
                item.remove();
                updateTotals();
            }
        }
    });

    if (discountInput) {
        discountInput.addEventListener('input', updateTotals);
    }
    if (paymentInput) {
        paymentInput.addEventListener('input', updateTotals);
    }

    // Quick Cash Preset Buttons
    document.querySelectorAll('[data-cash-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
            var preset = button.dataset.cashPreset;
            if (preset === 'exact') {
                if (paymentInput) paymentInput.value = currentTotal;
            } else {
                var amount = parseInt(preset, 10);
                if (paymentInput && !isNaN(amount)) {
                    paymentInput.value = amount;
                }
            }
            updateTotals();
        });
    });

    // Category Filter Pills on POS
    var categoryPills = document.querySelectorAll('[data-category-filter]');
    var productItems = document.querySelectorAll('.product-catalog-item');
    if (categoryPills.length > 0 && productItems.length > 0) {
        categoryPills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                categoryPills.forEach(function (p) { p.classList.remove('active'); });
                pill.classList.add('active');
                var targetCat = pill.dataset.categoryFilter;

                productItems.forEach(function (item) {
                    if (targetCat === 'all' || item.dataset.category === targetCat) {
                        item.classList.remove('d-none');
                    } else {
                        item.classList.add('d-none');
                    }
                });
            });
        });
    }

    // POS Submit Validation
    posForm.addEventListener('submit', function (event) {
        var items = Array.from(cart.querySelectorAll('.cart-item'));
        var hasItems = items.some(function (item) {
            return (parseInt(item.querySelector('.cart-qty').value, 10) || 0) > 0;
        });
        if (!hasItems) {
            event.preventDefault();
            window.alert('Keranjang belanja masih kosong. Pilih produk terlebih dahulu.');
            return;
        }

        var payment = Math.max(0, parseInt(paymentInput ? paymentInput.value : 0, 10) || 0);
        if (payment < currentTotal) {
            event.preventDefault();
            window.alert('Uang pembayaran masih kurang dari total belanja! Total: ' + formatRupiah(currentTotal) + ', Diterima: ' + formatRupiah(payment));
        }
    });

    // Initial total computation
    updateTotals();
});
