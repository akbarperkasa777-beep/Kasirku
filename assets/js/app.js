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
    var activeMemberDiscountPercent = 0;
    var activeMemberData = null;

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

        // Hitung otomatis diskon member 10% jika member aktif
        if (activeMemberDiscountPercent > 0) {
            var memberDiscount = Math.round(subtotal * (activeMemberDiscountPercent / 100));
            if (discountInput) {
                discountInput.value = memberDiscount;
            }
        }

        var discount = Math.min(subtotal, Math.max(0, parseInt(discountInput ? discountInput.value : 0, 10) || 0));
        var total = subtotal - discount;
        currentTotal = total;
        var payment = Math.max(0, parseInt(paymentInput ? paymentInput.value : 0, 10) || 0);

        var subtotalEl = document.getElementById('subtotal-label');
        if (subtotalEl) subtotalEl.textContent = formatRupiah(subtotal);

        var discountEl = document.getElementById('discount-label');
        if (discountEl) {
            if (activeMemberDiscountPercent > 0 && discount > 0) {
                discountEl.textContent = '-' + formatRupiah(discount) + ' (Member ' + activeMemberDiscountPercent + '%)';
            } else {
                discountEl.textContent = '-' + formatRupiah(discount);
            }
        }

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

    // RFID Member Scanner Controls & Event Handlers
    var rfidInput = document.getElementById('rfid-card-input');
    var rfidSubmitBtn = document.getElementById('rfid-submit-btn');
    var rfidStatusMsg = document.getElementById('rfid-status-msg');
    var memberActiveBox = document.getElementById('member-active-box');
    var memberActiveName = document.getElementById('member-active-name');
    var memberActiveInfo = document.getElementById('member-active-info');
    var cancelMemberBtn = document.getElementById('cancel-member-btn');
    var customerSelect = document.getElementById('customer-select');
    var posCsrfToken = document.getElementById('pos-csrf-token');
    var rfidLookupPending = false;

    function setRfidStatus(message, statusClass) {
        if (!rfidStatusMsg) return;
        rfidStatusMsg.innerHTML = message;
        rfidStatusMsg.className = 'small mt-2 ' + (statusClass || 'text-muted');
    }

    function applyMember(member) {
        activeMemberDiscountPercent = parseInt(member.diskon_persen, 10) || 10;
        activeMemberData = member;

        if (memberActiveBox && memberActiveName) {
            memberActiveName.textContent = member.nama_member;
            if (memberActiveInfo) {
                memberActiveInfo.textContent = 'ID: ' + (member.id_card_rfid || '-') + ' · Diskon Member ' + activeMemberDiscountPercent + '% Aktif';
            }
            memberActiveBox.classList.remove('d-none');
        }

        // Sinkronkan pilihan pelanggan pada select dropdown
        if (customerSelect) {
            var found = false;
            for (var i = 0; i < customerSelect.options.length; i++) {
                if (parseInt(customerSelect.options[i].value, 10) === parseInt(member.id_pelanggan, 10)) {
                    customerSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (!found && member.id_pelanggan) {
                var opt = document.createElement('option');
                opt.value = member.id_pelanggan;
                opt.textContent = member.nama_member + ' (Member)';
                opt.selected = true;
                customerSelect.appendChild(opt);
            }
        }

        setRfidStatus('<i class="bi bi-check-circle-fill text-success me-1"></i> Member aktif terverifikasi! Diskon otomatis ' + activeMemberDiscountPercent + '% diterapkan.', 'text-success');
        updateTotals();
    }

    function removeMember() {
        activeMemberDiscountPercent = 0;
        activeMemberData = null;
        if (memberActiveBox) {
            memberActiveBox.classList.add('d-none');
        }
        if (discountInput) {
            discountInput.value = 0;
        }
        setRfidStatus('<i class="bi bi-info-circle me-1"></i> Diskon member dibatalkan.', 'text-muted');
        updateTotals();
    }

    function lookupMember() {
        if (!rfidInput || rfidLookupPending) return;
        var idCard = rfidInput.value.trim();
        if (!idCard) {
            setRfidStatus('<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> Masukkan atau tap kartu RFID member terlebih dahulu.', 'text-warning');
            rfidInput.focus();
            return;
        }

        rfidLookupPending = true;
        setRfidStatus('<span class="spinner-border spinner-border-sm me-1" role="status"></span> Memeriksa kartu member...', 'text-primary');

        var formData = new URLSearchParams();
        formData.append('id_card', idCard);
        if (posCsrfToken) {
            formData.append('csrf_token', posCsrfToken.value);
        }

        fetch('ajax_cek_member.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: formData.toString()
        })
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || data.status !== 'success') {
                    throw new Error(data.message || 'Verifikasi kartu member gagal.');
                }
                return data;
            });
        })
        .then(function (data) {
            applyMember(data);
            rfidInput.value = '';
            // Kembalikan fokus ke scan barcode untuk kenyamanan kasir
            if (barcodeInput) {
                barcodeInput.focus();
            }
        })
        .catch(function (error) {
            removeMember();
            setRfidStatus('<i class="bi bi-x-circle-fill text-danger me-1"></i> ' + error.message, 'text-danger');
            rfidInput.select();
        })
        .finally(function () {
            rfidLookupPending = false;
        });
    }

    if (rfidInput) {
        rfidInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lookupMember();
            }
        });
    }
    if (rfidSubmitBtn) {
        rfidSubmitBtn.addEventListener('click', lookupMember);
    }
    if (cancelMemberBtn) {
        cancelMemberBtn.addEventListener('click', removeMember);
    }

    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            var selectedOption = customerSelect.options[customerSelect.selectedIndex];
            var rfid = selectedOption ? selectedOption.dataset.rfid : '';
            var status = selectedOption ? selectedOption.dataset.status : '';

            if (rfid && status === 'aktif') {
                applyMember({
                    id_pelanggan: customerSelect.value,
                    nama_member: selectedOption.textContent.replace(' (Member)', '').trim(),
                    id_card_rfid: rfid,
                    status_member: status,
                    diskon_persen: 10
                });
            } else if (activeMemberDiscountPercent > 0) {
                // Batalkan diskon member jika kasir mengganti ke pelanggan umum
                removeMember();
            }
        });
    }

    // Hotkey: F2 untuk fokus ke input RFID member, F1 untuk fokus ke barcode scanner
    window.addEventListener('keydown', function (event) {
        if (event.key === 'F2') {
            event.preventDefault();
            if (rfidInput) {
                rfidInput.focus();
                rfidInput.select();
            }
        } else if (event.key === 'F1') {
            event.preventDefault();
            if (barcodeInput) {
                barcodeInput.focus();
                barcodeInput.select();
            }
        }
    });

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
