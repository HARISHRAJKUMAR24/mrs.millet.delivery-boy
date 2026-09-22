/* =========================================================
   MRS MILL@ — TAKE ORDER (delivery boy panel)
   File: ./js/take-order.js
   Tabs (Menu / All), variant picker, cart, UPI QR with logo,
   confirm payment.
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL)
        ? window.BASE_URL
        : "./";

    /* ---------------- DOM ---------------- */
    const form        = document.getElementById("orderForm");
    const saveBtn     = document.getElementById("saveBtn");
    const saveText    = document.getElementById("saveBtnText");

    const custName    = document.getElementById("customer_name");
    const custMobile  = document.getElementById("customer_mobile");
    const aptIdInput  = document.getElementById("apartment_id");
    const divNameInp  = document.getElementById("division_name");
    const divChargeInp= document.getElementById("division_charge");

    const productSearch = document.getElementById("productSearch");
    const productGrid   = document.getElementById("productGrid");

    const cartList    = document.getElementById("cartList");
    const cartCount   = document.getElementById("cartCount");
    const subtotalTxt = document.getElementById("subtotalText");
    const deliveryTxt = document.getElementById("deliveryText");
    const totalTxt    = document.getElementById("totalText");

    const successOverlay = document.getElementById("successOverlay");
    const successText    = document.getElementById("successText");
    const successCode    = document.getElementById("successCode");
    const errorOverlay   = document.getElementById("errorOverlay");
    const errorText      = document.getElementById("errorText");
    const errorOkBtn     = document.getElementById("errorOkBtn");

    /* Variant modal */
    const vOverlay = document.getElementById("variantOverlay");
    const vThumb   = document.getElementById("vThumb");
    const vName    = document.getElementById("vName");
    const vCode    = document.getElementById("vCode");
    const vList    = document.getElementById("vList");
    const vCancel  = document.getElementById("vCancel");
    const vAdd     = document.getElementById("vAdd");

    /* UPI modal */
    const upiOverlay    = document.getElementById("upiOverlay");
    const upiOrderCode  = document.getElementById("upiOrderCode");
    const upiAmount     = document.getElementById("upiAmount");
    const upiQrBox      = document.getElementById("upiQrBox");
    const qrLogo        = document.getElementById("qrLogo");
    const upiOpenApp    = document.getElementById("upiOpenApp");
    const upiCopyLink   = document.getElementById("upiCopyLink");
    const upiCancelBtn  = document.getElementById("upiCancelBtn");
    const upiConfirmBtn = document.getElementById("upiConfirmBtn");
    const upiConfirmText= document.getElementById("upiConfirmText");

    if (!form) return;

    /* ---------------- STATE ---------------- */
    const state = {
        tab: "menu",
        productsMenu: [],
        productsAll:  [],
        cart: [],
        searchTerm: "",
        pendingVariant: null,

        /* UPI */
        pendingOrderId: 0,
        pendingOrderCode: "",
        pendingUpiString: "",
        qrInstance: null
    };

    /* ---------------- HELPERS ---------------- */
    function money(n) {
        return "₹" + (Number(n) || 0).toFixed(2);
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function showError(msg) {
        if (errorText) errorText.textContent = msg || "Please check the form.";
        if (errorOverlay) {
            errorOverlay.classList.add("show");
            errorOverlay.setAttribute("aria-hidden", "false");
            setTimeout(() => errorOkBtn && errorOkBtn.focus(), 60);
        }
    }

    function showSuccess(msg, code) {
        if (successText) successText.textContent = msg || "Order placed.";
        if (successCode) {
            if (code) {
                successCode.textContent = "ORDER: " + code;
                successCode.style.display = "inline-block";
            } else {
                successCode.style.display = "none";
            }
        }
        if (successOverlay) {
            successOverlay.classList.add("show");
            successOverlay.setAttribute("aria-hidden", "false");
        }
    }

    if (errorOkBtn) errorOkBtn.addEventListener("click", () => errorOverlay.classList.remove("show"));
    if (errorOverlay) errorOverlay.addEventListener("click", e => {
        if (e.target === errorOverlay) errorOverlay.classList.remove("show");
    });

    function setLoading(isLoading) {
        saveBtn.disabled = isLoading;
        saveText.innerHTML = isLoading
            ? '<span class="btn-spinner"></span> Creating...'
            : 'Place Order';
    }

    /* =====================================================
       APARTMENT + DIVISION DROPDOWNS
    ===================================================== */
    const dropdowns = {
        apartment: {
            wrap: document.getElementById("apartmentDd"),
            toggle: document.querySelector('[data-target="apartment"]'),
            list: document.querySelector('[data-list="apartment"]'),
            search: document.querySelector('[data-search="apartment"]')
        },
        division: {
            wrap: document.getElementById("divisionDd"),
            toggle: document.querySelector('[data-target="division"]'),
            list: document.querySelector('[data-list="division"]'),
            search: document.querySelector('[data-search="division"]')
        }
    };

    function setToggleText(toggle, text, asPlaceholder) {
        const el = toggle.querySelector(".sd-placeholder");
        if (!el) return;
        el.textContent = text;
        if (asPlaceholder) {
            el.classList.add("sd-placeholder");
            toggle.classList.remove("has-value");
        } else {
            el.classList.remove("sd-placeholder");
            toggle.classList.add("has-value");
        }
    }

    function closeAllDropdowns(except) {
        Object.values(dropdowns).forEach(dd => {
            if (dd.wrap && dd.wrap !== except) dd.wrap.classList.remove("open");
        });
    }

    function wireDropdown(key, onSelect) {
        const dd = dropdowns[key];
        if (!dd) return;

        dd.toggle.addEventListener("click", function (e) {
            e.stopPropagation();
            if (dd.toggle.disabled) return;

            const wasOpen = dd.wrap.classList.contains("open");
            closeAllDropdowns(dd.wrap);
            dd.wrap.classList.toggle("open", !wasOpen);

            if (!wasOpen && dd.search) setTimeout(() => dd.search.focus(), 60);
        });

        if (dd.search) {
            dd.search.addEventListener("input", () => {
                const q = dd.search.value.trim().toLowerCase();
                dd.list.querySelectorAll(".sd-option").forEach(opt => {
                    const hay = (opt.dataset.search || "").toLowerCase();
                    opt.style.display = !q || hay.includes(q) ? "" : "none";
                });
            });
        }

        dd.list.addEventListener("click", function (e) {
            const opt = e.target.closest(".sd-option");
            if (!opt) return;

            const id   = opt.dataset.id || "";
            const name = opt.dataset.name || "";

            dd.list.querySelectorAll(".sd-option").forEach(o => o.classList.remove("selected"));
            opt.classList.add("selected");

            setToggleText(dd.toggle, name, false);
            dd.wrap.classList.remove("open");
            if (dd.search) dd.search.value = "";
            dd.list.querySelectorAll(".sd-option").forEach(o => o.style.display = "");

            onSelect(id, name, opt);
        });
    }

    wireDropdown("apartment", function (id) {
        aptIdInput.value = id;
        fetchDivisions(id);

        divNameInp.value = "";
        divChargeInp.value = "";
        setToggleText(dropdowns.division.toggle, "Select division", true);
        dropdowns.division.toggle.disabled = false;
    });

    wireDropdown("division", function (id, name, opt) {
        divNameInp.value = name;
        divChargeInp.value = opt.dataset.charge || "";
        updateTotals();
    });

    document.addEventListener("click", function (e) {
        if (!e.target.closest(".sd-wrap")) closeAllDropdowns();
    });

    /* =====================================================
       LOAD APARTMENTS + DIVISIONS
    ===================================================== */
    function loadApartments() {
        dropdowns.apartment.list.innerHTML = '<div class="sd-empty">Loading...</div>';

        fetch(BASE_URL + "ajax/get-apartments.php", { credentials: "same-origin" })
            .then(r => r.json().catch(() => null))
            .then(data => {
                if (!data || !data.success || !Array.isArray(data.data)) {
                    dropdowns.apartment.list.innerHTML = '<div class="sd-empty">Failed to load.</div>';
                    return;
                }
                if (!data.data.length) {
                    dropdowns.apartment.list.innerHTML = '<div class="sd-empty">No apartments.</div>';
                    return;
                }

                dropdowns.apartment.list.innerHTML = data.data.map(a => `
                    <div class="sd-option"
                         data-id="${escapeHtml(a.id)}"
                         data-name="${escapeHtml(a.apartment_name)}"
                         data-search="${escapeHtml((a.apartment_name||'') + ' ' + (a.apartment_code||''))}">
                        <i class="bi bi-building"></i>
                        <div class="name">
                            ${escapeHtml(a.apartment_name)}
                            <div class="code">#${escapeHtml(a.apartment_code || '')}</div>
                        </div>
                    </div>
                `).join("");
            })
            .catch(() => {
                dropdowns.apartment.list.innerHTML = '<div class="sd-empty">Unable to connect.</div>';
            });
    }

    function fetchDivisions(apartmentId) {
        dropdowns.division.list.innerHTML = '<div class="sd-empty">Loading...</div>';

        fetch(BASE_URL + "ajax/get-apartment-divisions.php?id=" + encodeURIComponent(apartmentId), {
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(data => {
                if (!data || !data.success || !Array.isArray(data.data)) {
                    dropdowns.division.list.innerHTML = '<div class="sd-empty">Failed to load divisions.</div>';
                    return;
                }
                if (!data.data.length) {
                    dropdowns.division.list.innerHTML = '<div class="sd-empty">No divisions.</div>';
                    return;
                }

                dropdowns.division.list.innerHTML = data.data.map(d => `
                    <div class="sd-option"
                         data-id="${escapeHtml(d.division)}"
                         data-name="${escapeHtml(d.division)}"
                         data-charge="${escapeHtml(d.charge)}"
                         data-search="${escapeHtml(d.division)}">
                        <i class="bi bi-grid-3x3-gap"></i>
                        <div class="name">
                            ${escapeHtml(d.division)}
                            <div class="code">₹${escapeHtml(d.charge)}</div>
                        </div>
                    </div>
                `).join("");
            })
            .catch(() => {
                dropdowns.division.list.innerHTML = '<div class="sd-empty">Unable to connect.</div>';
            });
    }

    /* =====================================================
       PRODUCT TABS + LOAD
    ===================================================== */
    document.querySelectorAll(".pd-tab").forEach(tab => {
        tab.addEventListener("click", function () {
            document.querySelectorAll(".pd-tab").forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            state.tab = tab.dataset.tab;
            renderProducts();
        });
    });

    function loadProducts() {
        productGrid.innerHTML = '<div class="pd-empty"><i class="bi bi-hourglass-split"></i>Loading products...</div>';

        Promise.all([
            fetch(BASE_URL + "ajax/get-menu-products.php", { credentials: "same-origin" }).then(r => r.json().catch(() => null)),
            fetch(BASE_URL + "ajax/get-all-products.php",  { credentials: "same-origin" }).then(r => r.json().catch(() => null))
        ])
            .then(([menuData, allData]) => {
                state.productsMenu = (menuData && menuData.success && Array.isArray(menuData.data)) ? menuData.data : [];
                state.productsAll  = (allData  && allData.success  && Array.isArray(allData.data))  ? allData.data  : [];
                renderProducts();
            })
            .catch(() => {
                productGrid.innerHTML = '<div class="pd-empty"><i class="bi bi-exclamation-circle"></i>Unable to load products.</div>';
            });
    }

    function renderProducts() {
        const list = state.tab === "menu" ? state.productsMenu : state.productsAll;
        const q = (state.searchTerm || "").trim().toLowerCase();

        let filtered = list;
        if (q) {
            filtered = list.filter(p => {
                const hay = ((p.name || "") + " " + (p.code || "")).toLowerCase();
                return hay.includes(q);
            });
        }

        if (!filtered.length) {
            productGrid.innerHTML = `
                <div class="pd-empty">
                    <i class="bi bi-box"></i>
                    ${state.tab === "menu" ? "No menu products right now." : "No products found."}
                </div>`;
            return;
        }

        productGrid.innerHTML = filtered.map(p => {

            const thumb = p.image
                ? `<img src="${escapeHtml(p.image)}" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='📦';">`
                : '📦';

            const variantCount = Array.isArray(p.variants) ? p.variants.length : 0;
            const minPrice = variantCount
                ? Math.min(...p.variants.map(v => Number(v.price) || 0))
                : 0;

            const badge = variantCount > 1 ? `<span class="pd-badge">${variantCount} sizes</span>` : '';

            return `
                <div class="pd-card" data-id="${escapeHtml(p.id)}">
                    <div class="pd-thumb">${thumb}</div>
                    <div class="pd-info">
                        <div class="pd-name">${escapeHtml(p.name)}${badge}</div>
                        <div class="pd-meta">#${escapeHtml(p.code || '')}</div>
                        ${minPrice ? `<div class="pd-price">From ₹${minPrice.toFixed(2)}</div>` : ''}
                    </div>
                </div>
            `;
        }).join("");
    }

    productGrid.addEventListener("click", function (e) {
        const card = e.target.closest(".pd-card");
        if (!card) return;

        const id = card.dataset.id;
        const list = state.tab === "menu" ? state.productsMenu : state.productsAll;
        const product = list.find(p => String(p.id) === String(id));
        if (!product) return;

        if (Array.isArray(product.variants) && product.variants.length > 0) {
            openVariantModal(product);
        } else {
            addToCart(product, null);
        }
    });

    productSearch.addEventListener("input", function () {
        state.searchTerm = productSearch.value;
        renderProducts();
    });

    /* =====================================================
       VARIANT MODAL
    ===================================================== */
    function openVariantModal(product) {
        state.pendingVariant = {
            product: product,
            selectedVariantId: product.variants[0].id
        };

        const thumb = product.image
            ? `<img src="${escapeHtml(product.image)}" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='📦';">`
            : '📦';

        vThumb.innerHTML = thumb;
        vName.textContent = product.name;
        vCode.textContent = "#" + (product.code || '');

        vList.innerHTML = product.variants.map(v => `
            <div class="variant-option ${String(v.id) === String(state.pendingVariant.selectedVariantId) ? 'selected' : ''}"
                 data-vid="${escapeHtml(v.id)}">
                <div class="variant-radio"></div>
                <div class="variant-body">
                    <div class="variant-name">${escapeHtml(v.quantity_name || v.quantity + ' ' + v.quantity_unit)}</div>
                    <div class="variant-qty">${escapeHtml(v.quantity)} ${escapeHtml(v.quantity_unit)}</div>
                </div>
                <div class="variant-price">₹${Number(v.price).toFixed(2)}</div>
            </div>
        `).join("");

        vOverlay.classList.add("show");
        vOverlay.setAttribute("aria-hidden", "false");
    }

    function closeVariantModal() {
        vOverlay.classList.remove("show");
        vOverlay.setAttribute("aria-hidden", "true");
        state.pendingVariant = null;
    }

    vList.addEventListener("click", function (e) {
        const opt = e.target.closest(".variant-option");
        if (!opt || !state.pendingVariant) return;

        vList.querySelectorAll(".variant-option").forEach(o => o.classList.remove("selected"));
        opt.classList.add("selected");
        state.pendingVariant.selectedVariantId = opt.dataset.vid;
    });

    if (vCancel) vCancel.addEventListener("click", closeVariantModal);
    if (vOverlay) vOverlay.addEventListener("click", e => {
        if (e.target === vOverlay) closeVariantModal();
    });

    if (vAdd) {
        vAdd.addEventListener("click", function () {
            if (!state.pendingVariant) return;

            const { product, selectedVariantId } = state.pendingVariant;
            const variant = product.variants.find(v => String(v.id) === String(selectedVariantId));

            addToCart(product, variant);
            closeVariantModal();
        });
    }

    /* =====================================================
       CART
    ===================================================== */
    function makeCartKey(productId, variantId) {
        return "p" + productId + "_v" + (variantId || 0);
    }

    function addToCart(product, variant) {
        const variantId = variant ? variant.id : null;
        const key = makeCartKey(product.id, variantId);

        const existing = state.cart.find(c => c.key === key);
        if (existing) {
            existing.qty += 1;
            renderCart();
            return;
        }

        const price = variant ? Number(variant.price) : (product.price || 0);

        state.cart.push({
            key: key,
            productId: product.id,
            code: product.code,
            name: product.name,
            image: product.image || "",
            variantId: variantId,
            variantName: variant ? (variant.quantity_name || (variant.quantity + ' ' + variant.quantity_unit)) : "",
            variantQty: variant ? (variant.quantity + ' ' + variant.quantity_unit) : "",
            price: price,
            qty: 1
        });

        renderCart();
    }

    function renderCart() {
        const count = state.cart.reduce((s, c) => s + c.qty, 0);
        cartCount.textContent = count;

        if (!state.cart.length) {
            cartList.innerHTML = `
                <div class="cart-empty">
                    <i class="bi bi-basket"></i>
                    No products added yet.<br>
                    Pick from the left to begin.
                </div>`;
            updateTotals();
            return;
        }

        cartList.innerHTML = state.cart.map(c => {

            const thumb = c.image
                ? `<img src="${escapeHtml(c.image)}" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='📦';">`
                : '📦';

            const variantLine = c.variantName ? ` · ${escapeHtml(c.variantName)}` : '';

            return `
                <div class="cart-item" data-key="${escapeHtml(c.key)}">
                    <div class="cart-thumb">${thumb}</div>
                    <div class="cart-info">
                        <div class="cart-name">${escapeHtml(c.name)}</div>
                        <div class="cart-meta">
                            <span class="cart-price">${money(c.price)}</span>${variantLine}
                        </div>
                    </div>
                    <div class="cart-qty">
                        <button type="button" data-act="dec"><i class="bi bi-dash"></i></button>
                        <span>${c.qty}</span>
                        <button type="button" data-act="inc"><i class="bi bi-plus"></i></button>
                    </div>
                    <button type="button" class="cart-remove" data-act="rm">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            `;
        }).join("");

        updateTotals();
    }

    cartList.addEventListener("click", function (e) {
        const btn = e.target.closest("[data-act]");
        if (!btn) return;

        const item = e.target.closest(".cart-item");
        if (!item) return;

        const key = item.dataset.key;
        const c = state.cart.find(x => x.key === key);
        if (!c) return;

        const act = btn.dataset.act;
        if (act === "inc") c.qty += 1;
        if (act === "dec") c.qty = Math.max(1, c.qty - 1);
        if (act === "rm") state.cart = state.cart.filter(x => x.key !== key);

        renderCart();
    });

    function updateTotals() {
        const subtotal = state.cart.reduce((s, c) => s + (c.price * c.qty), 0);
        const charge   = Number(divChargeInp.value || 0);
        const total    = subtotal + charge;

        subtotalTxt.textContent = money(subtotal);
        deliveryTxt.textContent = money(charge);
        totalTxt.textContent    = money(total);
    }

    /* =====================================================
       UPI QR MODAL (with logo overlay preserved)
    ===================================================== */
    function renderQr(upiString) {
        if (!upiQrBox) return;

        /* Save the logo overlay HTML before we clear */
        const logoHtml = qrLogo ? qrLogo.outerHTML : "";

        /* Reset the QR box (removes any old canvas), then re-append logo */
        upiQrBox.innerHTML = logoHtml;

        if (typeof QRCode === "undefined") {
            upiQrBox.insertAdjacentHTML(
                "afterbegin",
                '<div style="color:#b51f2c;font-size:12px;padding:20px;text-align:center;">QR library not loaded.<br>Please refresh.</div>'
            );
            return;
        }

        try {
            /* QR holder behind the logo */
            const qrHolder = document.createElement("div");
            qrHolder.style.width = "100%";
            qrHolder.style.height = "100%";
            qrHolder.style.display = "flex";
            qrHolder.style.alignItems = "center";
            qrHolder.style.justifyContent = "center";
            upiQrBox.insertBefore(qrHolder, upiQrBox.firstChild);

            state.qrInstance = new QRCode(qrHolder, {
                text: upiString,
                width: 200,
                height: 200,
                colorDark: "#302923",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });

        } catch (e) {
            upiQrBox.insertAdjacentHTML(
                "afterbegin",
                '<div style="color:#b51f2c;font-size:12px;padding:20px;text-align:center;">Failed to generate QR.</div>'
            );
        }
    }

    function openUpiModal(data) {
        state.pendingOrderId    = data.order_id;
        state.pendingOrderCode  = data.order_code;
        state.pendingUpiString  = data.upi_string || "";

        if (upiOrderCode) upiOrderCode.textContent = data.order_code || "";
        if (upiAmount)    upiAmount.textContent    = money(data.total);

        if (upiOpenApp) upiOpenApp.href = data.upi_link || data.upi_string || "#";

        renderQr(state.pendingUpiString);

        upiOverlay.classList.add("show");
        upiOverlay.setAttribute("aria-hidden", "false");
    }

    function closeUpiModal() {
        if (!upiOverlay) return;
        upiOverlay.classList.remove("show");
        upiOverlay.setAttribute("aria-hidden", "true");

        /* Keep the logo overlay for the next open */
        if (upiQrBox) {
            const logoHtml = qrLogo ? qrLogo.outerHTML : "";
            upiQrBox.innerHTML = logoHtml;
        }
        state.qrInstance = null;
    }

    if (upiCancelBtn) upiCancelBtn.addEventListener("click", closeUpiModal);
    if (upiOverlay) upiOverlay.addEventListener("click", e => {
        if (e.target === upiOverlay) closeUpiModal();
    });

    /* Copy UPI link */
    if (upiCopyLink) {
        upiCopyLink.addEventListener("click", function (e) {
            e.preventDefault();
            if (!state.pendingUpiString) return;

            navigator.clipboard.writeText(state.pendingUpiString)
                .then(() => {
                    const original = upiCopyLink.innerHTML;
                    upiCopyLink.innerHTML = '<i class="bi bi-check-lg"></i> Copied';
                    setTimeout(() => upiCopyLink.innerHTML = original, 1500);
                })
                .catch(() => {});
        });
    }

    /* Confirm payment */
    if (upiConfirmBtn) {
        upiConfirmBtn.addEventListener("click", function () {

            if (state.pendingOrderId <= 0) {
                showError("Invalid order. Please try again.");
                return;
            }

            upiConfirmBtn.disabled = true;
            upiConfirmText.innerHTML = '<span class="btn-spinner"></span> Confirming...';

            const fd = new FormData();
            fd.append("order_id", state.pendingOrderId);

            fetch(BASE_URL + "ajax/confirm-order-payment.php", {
                method: "POST",
                body: fd,
                credentials: "same-origin"
            })
                .then(r => r.json().catch(() => null))
                .then(res => {

                    upiConfirmBtn.disabled = false;
                    upiConfirmText.innerHTML = 'Confirm Payment';

                    if (res && res.success) {
                        closeUpiModal();

                        showSuccess(
                            res.message || "Payment confirmed. Order placed successfully.",
                            state.pendingOrderCode
                        );

                        /* Reset form */
                        form.reset();
                        aptIdInput.value = "";
                        divNameInp.value = "";
                        divChargeInp.value = "";
                        state.cart = [];
                        renderCart();

                        setToggleText(dropdowns.apartment.toggle, "Select apartment", true);
                        setToggleText(dropdowns.division.toggle, "Select apartment first", true);
                        dropdowns.division.toggle.disabled = true;

                        state.pendingOrderId = 0;
                        state.pendingOrderCode = "";
                        state.pendingUpiString = "";

                    } else {
                        showError((res && res.message) || "Failed to confirm payment.");
                    }
                })
                .catch(() => {
                    upiConfirmBtn.disabled = false;
                    upiConfirmText.innerHTML = 'Confirm Payment';
                    showError("Unable to connect to server.");
                });
        });
    }

    /* =====================================================
       SUBMIT — creates order, opens UPI modal
    ===================================================== */
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        const name   = custName.value.trim();
        const mobile = custMobile.value.trim();
        const aptId  = aptIdInput.value.trim();
        const div    = divNameInp.value.trim();
        const charge = divChargeInp.value.trim();

        if (!name)   return showError("Customer name is required.");
        if (!mobile) return showError("Mobile number is required.");
        if (!/^[0-9]{10,15}$/.test(mobile))
            return showError("Mobile number must be 10–15 digits.");
        if (!aptId)  return showError("Please select an apartment.");
        if (!div)    return showError("Please select a division.");
        if (!state.cart.length)
            return showError("Please add at least one product.");

        setLoading(true);

        const payload = {
            customer_name:   name,
            customer_mobile: mobile,
            apartment_id:    aptId,
            division:        div,
            division_charge: charge,
            products: state.cart.map(c => ({
                product_id:   c.productId,
                code:         c.code,
                name:         c.name,
                image:        c.image,
                variant_id:   c.variantId,
                variant_name: c.variantName,
                variant_qty:  c.variantQty,
                price:        c.price,
                qty:          c.qty
            }))
        };

        const fd = new FormData();
        fd.append("payload", JSON.stringify(payload));

        fetch(BASE_URL + "ajax/save-order.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(data => {

                setLoading(false);

                if (data && data.success && data.data) {
                    openUpiModal(data.data);
                } else {
                    showError((data && data.message) || "Failed to create order.");
                }
            })
            .catch(() => {
                setLoading(false);
                showError("Unable to connect to server.");
            });
    });

    /* =====================================================
       MOBILE — DIGITS ONLY
    ===================================================== */
    custMobile.addEventListener("input", function () {
        custMobile.value = custMobile.value.replace(/[^0-9]/g, "").slice(0, 15);
    });

    /* =====================================================
       INIT
    ===================================================== */
    loadApartments();
    loadProducts();

})();