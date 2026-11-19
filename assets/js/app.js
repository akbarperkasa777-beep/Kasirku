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
    // Jika POS logic sudah diinisialisasi oleh script mandiri di pos.php, lewati agar tidak terjadi event listener ganda
    if (window.__POS_INITIALIZED__) {
        return;
    }
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

        var clearCartBtn = document.getElementById('btn-clear-pos-cart');
        if (clearCartBtn) {
            clearCartBtn.classList.toggle('d-none', items.length === 0);
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
    var memberCardId = document.getElementById('member-card-id');
    var rfidLookupPending = false;

    function setRfidStatus(message, statusClass) {
        if (!rfidStatusMsg) return;
        rfidStatusMsg.innerHTML = message;
        rfidStatusMsg.className = 'small mt-2 ' + (statusClass || 'text-muted');
    }

    function applyMember(member) {
        activeMemberDiscountPercent = Number(member.diskon_persen) === 10 && member.status_member === 'aktif' ? 10 : 0;
        if (activeMemberDiscountPercent === 0 || !member.id_card_rfid) {
            removeMember();
            return;
        }
        activeMemberData = member;
        if (memberCardId) {
            memberCardId.value = member.id_card_rfid || '';
        }

        if (memberActiveBox && memberActiveName) {
            memberActiveName.textContent = member.nama_pelanggan || member.nama_member;
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
        if (memberCardId) {
            memberCardId.value = '';
        }
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
                if (!response.ok || data.status !== 'success' || data.status_member !== 'aktif' || Number(data.diskon_persen) !== 10 || !data.id_card_rfid) {
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
        rfidSubmitBtn.addEventListener('click', function () {
            if (rfidInput) {
                rfidInput.focus();
            }
        });
    }
    if (cancelMemberBtn) {
        cancelMemberBtn.addEventListener('click', removeMember);
    }

    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            if (activeMemberData && customerSelect.value !== String(activeMemberData.id_pelanggan)) {
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

    // Kosongkan Seluruh Keranjang Belanja POS
    var clearCartBtn = document.getElementById('btn-clear-pos-cart');
    if (clearCartBtn) {
        clearCartBtn.addEventListener('click', function () {
            if (!window.confirm('Kosongkan semua item dari keranjang belanja kasir?')) return;
            var cartLines = cart.querySelectorAll('.cart-item');
            cartLines.forEach(function (line) { line.remove(); });
            if (paymentInput) paymentInput.value = '';
            removeMember();
            updateTotals();
        });
    }

    // Toggle Metode Pembayaran Kasir: Tunai vs Duitku Non-Tunai
    var payTypeCash = document.getElementById('pay-type-cash');
    var payTypeDuitku = document.getElementById('pay-type-duitku');
    var cashContainer = document.getElementById('pos-cash-container');
    var duitkuContainer = document.getElementById('pos-duitku-container');

    function switchPaymentMethod(method) {
        if (method === 'duitku') {
            if (cashContainer) cashContainer.classList.add('d-none');
            if (duitkuContainer) duitkuContainer.classList.remove('d-none');
            if (paymentInput) {
                paymentInput.required = false;
                paymentInput.value = '';
            }
        } else {
            if (cashContainer) cashContainer.classList.remove('d-none');
            if (duitkuContainer) duitkuContainer.classList.add('d-none');
            if (paymentInput) {
                paymentInput.required = true;
            }
        }
    }

    if (payTypeCash) {
        payTypeCash.addEventListener('change', function () {
            if (this.checked) switchPaymentMethod('cash');
        });
    }
    if (payTypeDuitku) {
        payTypeDuitku.addEventListener('change', function () {
            if (this.checked) switchPaymentMethod('duitku');
        });
    }

    // Modal & Status Tagihan Duitku POS
    var posDuitkuModalEl = document.getElementById('pos-duitku-modal');
    var posDuitkuModal = null;
    var btnSubmitDuitku = document.getElementById('pos-submit-duitku-btn');
    var posCountdownInterval = null;
    var posStatusPollingInterval = null;
    var posActiveOrderId = null;
    var posActiveMerchantOrderId = null;
    var posRemainingSeconds = 600;

    function formatMMSS(seconds) {
        var mins = Math.floor(seconds / 60);
        var secs = seconds % 60;
        return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    function startPosCountdown(seconds) {
        stopPosCountdown();
        posRemainingSeconds = seconds || 600;
        var timerEl = document.getElementById('pos-duitku-countdown-timer');
        if (timerEl) {
            timerEl.textContent = formatMMSS(posRemainingSeconds);
            timerEl.className = 'badge bg-warning text-dark fs-5 fw-bold font-monospace px-3 py-1 shadow-sm';
        }

        posCountdownInterval = setInterval(function () {
            posRemainingSeconds--;
            if (timerEl) {
                timerEl.textContent = formatMMSS(Math.max(0, posRemainingSeconds));
                if (posRemainingSeconds <= 60) {
                    timerEl.className = 'badge bg-danger text-white fs-5 fw-bold font-monospace px-3 py-1 shadow-sm';
                }
            }
            if (posRemainingSeconds <= 0) {
                stopPosCountdown();
                handlePosPaymentExpired();
            }
        }, 1000);
    }

    function stopPosCountdown() {
        if (posCountdownInterval) {
            clearInterval(posCountdownInterval);
            posCountdownInterval = null;
        }
    }

    function startPosPolling(orderId, merchantOrderId) {
        stopPosPolling();
        posActiveOrderId = orderId;
        posActiveMerchantOrderId = merchantOrderId;

        checkPosPaymentStatus();
        posStatusPollingInterval = setInterval(checkPosPaymentStatus, 3000);
    }

    function stopPosPolling() {
        if (posStatusPollingInterval) {
            clearInterval(posStatusPollingInterval);
            posStatusPollingInterval = null;
        }
    }

    function checkPosPaymentStatus() {
        if (!posActiveOrderId && !posActiveMerchantOrderId) return;

        var url = 'cek_status_transaksi.php?order_id=' + encodeURIComponent(posActiveOrderId || '')
            + '&merchant_order_id=' + encodeURIComponent(posActiveMerchantOrderId || '')
            + '&_t=' + Date.now();

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data || !data.success) return;

            if (data.is_paid || data.status_pembayaran === 'lunas' || data.status === 'lunas') {
                handlePosPaymentSuccess(data);
            } else if (data.status === 'dibatalkan' || data.is_expired) {
                handlePosPaymentExpired();
            }
        })
        .catch(function (err) {
            console.warn('POS Duitku polling status:', err);
        });
    }

    function handlePosPaymentSuccess(data) {
        stopPosPolling();
        stopPosCountdown();

        var pendingView = document.getElementById('pos-duitku-pending-view');
        var successView = document.getElementById('pos-duitku-success-view');
        var expiredView = document.getElementById('pos-duitku-expired-view');

        if (pendingView) pendingView.classList.add('d-none');
        if (expiredView) expiredView.classList.add('d-none');
        if (successView) {
            successView.classList.remove('d-none');
            var trxId = data.order_id || posActiveOrderId || '-';
            var trxCode = data.trx_code || ('TRX-' + String(trxId).padStart(6, '0'));
            var orderId = data.merchant_order_id || posActiveMerchantOrderId || '-';

            document.getElementById('pos-success-trx').textContent = trxCode;
            document.getElementById('pos-success-order-id').textContent = orderId;
            document.getElementById('pos-success-amount').textContent = data.total_belanja_formatted || formatRupiah(data.total_belanja || currentTotal);
            document.getElementById('pos-success-method').textContent = data.metode_pembayaran || 'DUITKU';
            document.getElementById('pos-success-time').textContent = data.tanggal_pembayaran || 'Baru saja';

            var receiptBtn = document.getElementById('pos-success-receipt-btn');
            if (receiptBtn) {
                receiptBtn.href = data.redirect_url || ('cetak_struk.php?id=' + trxId + '&print=1');
            }
        }

        // Kosongkan keranjang kasir karena pembayaran telah lunas
        var cartLines = cart.querySelectorAll('.cart-item');
        cartLines.forEach(function (line) { line.remove(); });
        if (paymentInput) paymentInput.value = '';
        removeMember();
        updateTotals();
    }

    function handlePosPaymentExpired() {
        stopPosPolling();
        stopPosCountdown();

        var pendingView = document.getElementById('pos-duitku-pending-view');
        var successView = document.getElementById('pos-duitku-success-view');
        var expiredView = document.getElementById('pos-duitku-expired-view');

        if (pendingView) pendingView.classList.add('d-none');
        if (successView) successView.classList.add('d-none');
        if (expiredView) expiredView.classList.remove('d-none');

        if (posActiveOrderId) {
            fetch('cek_status_transaksi.php?action=expire&order_id=' + encodeURIComponent(posActiveOrderId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).catch(function () {});
        }
    }

    // Inisiasi Pembayaran Duitku Non-Tunai dari Kasir
    if (btnSubmitDuitku) {
        btnSubmitDuitku.addEventListener('click', function () {
            var items = Array.from(cart.querySelectorAll('.cart-item'));
            var hasItems = items.some(function (item) {
                return (parseInt(item.querySelector('.cart-qty').value, 10) || 0) > 0;
            });
            if (!hasItems) {
                window.alert('Keranjang belanja kasir masih kosong. Masukkan minimal satu produk!');
                return;
            }

            var channelSelect = document.getElementById('pos-duitku-channel');
            var selectedChannel = channelSelect ? channelSelect.value : 'BR';

            var originalBtnHtml = btnSubmitDuitku.innerHTML;
            btnSubmitDuitku.disabled = true;
            btnSubmitDuitku.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Menghubungkan ke Duitku Sandbox...';

            var formData = new FormData();
            if (posCsrfToken) {
                formData.append('csrf_token', posCsrfToken.value);
            }
            if (customerSelect && customerSelect.value) {
                formData.append('id_pelanggan', customerSelect.value);
            }
            if (memberCardId && memberCardId.value) {
                formData.append('id_card_rfid', memberCardId.value);
            }
            formData.append('payment_method', selectedChannel);

            items.forEach(function (item) {
                var pId = item.dataset.id;
                var qtyInput = item.querySelector('.cart-qty');
                var qty = Math.max(0, parseInt(qtyInput ? qtyInput.value : 0, 10) || 0);
                if (qty > 0) {
                    formData.append('qty[' + pId + ']', qty);
                }
            });

            fetch('proses_duitku.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Pembuatan tagihan Duitku gagal.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (!posDuitkuModal && posDuitkuModalEl && window.bootstrap) {
                    posDuitkuModal = bootstrap.Modal.getOrCreateInstance(posDuitkuModalEl);
                }

                var pendingView = document.getElementById('pos-duitku-pending-view');
                var successView = document.getElementById('pos-duitku-success-view');
                var expiredView = document.getElementById('pos-duitku-expired-view');

                if (pendingView) pendingView.classList.remove('d-none');
                if (successView) successView.classList.add('d-none');
                if (expiredView) expiredView.classList.add('d-none');

                // Isi rincian pada modal
                var custText = customerSelect && customerSelect.selectedIndex >= 0 ? customerSelect.options[customerSelect.selectedIndex].text : 'Pelanggan Umum (Walk-in)';
                document.getElementById('pos-modal-customer').textContent = custText;
                document.getElementById('pos-modal-method').textContent = data.payment_method_name || 'Duitku';
                document.getElementById('pos-modal-total').textContent = data.total_belanja_formatted || formatRupiah(data.total_belanja);

                var discountRow = document.getElementById('pos-modal-discount-row');
                var discountEl = document.getElementById('pos-modal-discount');
                if (data.diskon > 0) {
                    if (discountRow) discountRow.classList.remove('d-none');
                    if (discountEl) discountEl.textContent = '-' + formatRupiah(data.diskon) + ' (10% RFID)';
                } else if (discountRow) {
                    discountRow.classList.add('d-none');
                }

                document.getElementById('pos-modal-order-id').textContent = data.merchant_order_id || '-';

                var boxVa = document.getElementById('pos-box-va');
                var boxQris = document.getElementById('pos-box-qris');
                var vaNumberEl = document.getElementById('pos-modal-va-number');
                var refEl = document.getElementById('pos-modal-ref');

                if (data.is_va || (data.va_number && data.va_number !== '')) {
                    if (boxVa) boxVa.classList.remove('d-none');
                    if (vaNumberEl) vaNumberEl.textContent = data.va_number || '-';
                    if (boxQris) boxQris.classList.add('d-none');
                } else if (data.is_qris || (data.reference && data.reference !== '')) {
                    if (boxQris) boxQris.classList.remove('d-none');
                    if (refEl) refEl.textContent = data.reference || '-';
                    if (boxVa) boxVa.classList.add('d-none');
                } else {
                    if (boxVa) boxVa.classList.add('d-none');
                    if (boxQris) boxQris.classList.remove('d-none');
                    if (refEl) refEl.textContent = data.reference || '-';
                }

                var btnOpenDemo = document.getElementById('pos-btn-open-demo');
                if (btnOpenDemo && data.demo_simulator_url) {
                    btnOpenDemo.href = data.demo_simulator_url;
                }

                var btnOpenPortal = document.getElementById('pos-btn-open-portal');
                if (btnOpenPortal) {
                    if (data.payment_url) {
                        btnOpenPortal.href = data.payment_url;
                        btnOpenPortal.classList.remove('d-none');
                    } else {
                        btnOpenPortal.classList.add('d-none');
                    }
                }

                if (posDuitkuModal) {
                    posDuitkuModal.show();
                }
                startPosCountdown(data.expiry_seconds || 600);
                startPosPolling(data.order_id, data.merchant_order_id);
            })
            .catch(function (error) {
                window.alert('Gagal membuat tagihan Duitku: ' + error.message);
            })
            .finally(function () {
                btnSubmitDuitku.disabled = false;
                btnSubmitDuitku.innerHTML = originalBtnHtml;
            });
        });
    }

    // Tombol Salin Nilai pada Modal Tagihan POS
    var btnCopyVa = document.getElementById('pos-btn-copy-va');
    if (btnCopyVa) {
        btnCopyVa.addEventListener('click', function () {
            var text = document.getElementById('pos-modal-va-number').textContent.trim();
            if (text && text !== '-') {
                navigator.clipboard.writeText(text).then(function () {
                    btnCopyVa.innerHTML = '<i class="bi bi-check-lg me-1 text-success"></i>Tersalin!';
                    setTimeout(function () { btnCopyVa.innerHTML = '<i class="bi bi-clipboard me-1"></i>Salin VA'; }, 2000);
                }).catch(function () {
                    window.prompt('Salin VA berikut:', text);
                });
            }
        });
    }

    var btnCopyRef = document.getElementById('pos-btn-copy-ref');
    if (btnCopyRef) {
        btnCopyRef.addEventListener('click', function () {
            var text = document.getElementById('pos-modal-ref').textContent.trim();
            if (text && text !== '-') {
                navigator.clipboard.writeText(text).then(function () {
                    btnCopyRef.innerHTML = '<i class="bi bi-check-lg me-1 text-success"></i>Tersalin!';
                    setTimeout(function () { btnCopyRef.innerHTML = '<i class="bi bi-clipboard me-1"></i>Salin Ref'; }, 2000);
                }).catch(function () {
                    window.prompt('Salin Ref berikut:', text);
                });
            }
        });
    }

    var btnCopyOrder = document.getElementById('pos-btn-copy-order');
    if (btnCopyOrder) {
        btnCopyOrder.addEventListener('click', function () {
            var text = document.getElementById('pos-modal-order-id').textContent.trim();
            if (text && text !== '-') {
                navigator.clipboard.writeText(text).then(function () {
                    btnCopyOrder.innerHTML = '<i class="bi bi-check-lg me-1 text-success"></i>Tersalin!';
                    setTimeout(function () { btnCopyOrder.innerHTML = '<i class="bi bi-clipboard me-1"></i>Salin Order ID'; }, 2000);
                });
            }
        });
    }

    // Tombol Cek Status Sekarang secara Manual pada Modal POS
    var btnCheckNow = document.getElementById('pos-btn-check-now');
    if (btnCheckNow) {
        btnCheckNow.addEventListener('click', function () {
            btnCheckNow.disabled = true;
            btnCheckNow.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Memeriksa...';
            checkPosPaymentStatus();
            setTimeout(function () {
                btnCheckNow.disabled = false;
                btnCheckNow.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Cek Status Sekarang';
            }, 1200);
        });
    }

    // Tombol Transaksi Baru pada Layar Sukses POS
    var btnSuccessNew = document.getElementById('pos-success-new-btn');
    if (btnSuccessNew) {
        btnSuccessNew.addEventListener('click', function () {
            if (posDuitkuModal) {
                posDuitkuModal.hide();
            }
        });
    }

    // Hentikan Polling dan Timer jika modal ditutup
    if (posDuitkuModalEl) {
        posDuitkuModalEl.addEventListener('hidden.bs.modal', function () {
            stopPosPolling();
            stopPosCountdown();
        });
    }

    // POS Submit Validation (Khusus Pembayaran Tunai)
    posForm.addEventListener('submit', function (event) {
        if (payTypeDuitku && payTypeDuitku.checked) {
            event.preventDefault();
            return;
        }

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
