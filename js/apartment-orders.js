/* =========================================================
   MRS MILL@ — APARTMENT ORDERS (delivery boy)
   File: ./js/apartment-orders.js
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL) ? window.BASE_URL : "./";
    const ORDERS = Array.isArray(window.ORDERS) ? window.ORDERS : [];
    const SUMMARY = window.CONTAINER_SUMMARY || {};

    const tbody = document.getElementById("aoBody");
    const toast = document.getElementById("aoToast");
    const tabs = document.querySelectorAll(".ao-tab");
    const statusIn = document.getElementById("statusInput");
    const dateIn = document.getElementById("dateInput");
    const filterForm = document.getElementById("filterForm");

    /* Details modal */
    const modal = document.getElementById("aoModal");
    const amCode = document.getElementById("amCode");
    const amDate = document.getElementById("amDate");
    const amBody = document.getElementById("amBody");
    const amClose = document.getElementById("amClose");

    /* Container modal */
    const containerModal = document.getElementById("aoContainerModal");
    const containerModalSub = document.getElementById("containerModalSub");
    const containerModalInfo = document.getElementById("containerModalInfo");
    const containerBreakdown = document.getElementById("containerBreakdown");
    const containerModalClose = document.getElementById("containerModalClose");
    const containerForm = document.getElementById("containerForm");
    const containerOrderId = document.getElementById("containerOrderId");
    const containerMobile = document.getElementById("containerMobile");
    const containerCount = document.getElementById("containerCount");
    const containerNote = document.getElementById("containerNote");
    const containerRefund = document.getElementById("containerRefund");
    const containerQtyHint = document.getElementById("containerQtyHint");
    const containerFullBtn = document.getElementById("containerFullBtn");
    const containerCancel = document.getElementById("containerCancel");
    const containerSubmit = document.getElementById("containerSubmit");
    const containerSubmitText = document.getElementById("containerSubmitText");

    /* Wallet modal */
    const walletModal = document.getElementById("aoWalletModal");
    const walletModalClose = document.getElementById("walletModalClose");
    const walletModalSub = document.getElementById("walletModalSub");
    const walletModalInfo = document.getElementById("walletModalInfo");
    const walletMobileInp = document.getElementById("walletMobile");
    const walletTxnType = document.getElementById("walletTxnType");
    const walletAmount = document.getElementById("walletAmount");
    const walletNote = document.getElementById("walletNote");
    const walletBalanceEl = document.getElementById("walletBalance");
    const walletStatusLbl = document.getElementById("walletStatusLabel");
    const walletPreviewEl = document.getElementById("walletPreview");
    const walletTabs = document.querySelectorAll("#walletTabs .ao-wallet-tab");
    const walletCancel = document.getElementById("walletCancel");
    const walletSubmit = document.getElementById("walletSubmit");
    const walletSubmitText = document.getElementById("walletSubmitText");
    const walletHistory = document.getElementById("walletHistory");
    const walletRefreshH = document.getElementById("walletRefreshHistory");
    const walletForm = document.getElementById("walletForm");

    let editingContainer = null;
    let walletCustomer = null;
    let walletActiveTab = "credit";

    const DELIVERY_LOCK_SECONDS = 5 * 3600;

    /* ---------- Helpers ---------- */
    function showToast(msg, type) {
        if (!toast) return;
        toast.textContent = msg;
        toast.className = "ao-toast show" + (type === "error" ? " error" : "");
        clearTimeout(toast._t);
        toast._t = setTimeout(() => (toast.className = "ao-toast"), 2800);
    }

    function esc(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;")
            .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }

    function money(n) {
        return "₹" + (Number(n) || 0).toFixed(2);
    }

    function formatDate(s) {
        if (!s) return "—";
        try {
            return new Date(s.replace(" ", "T")).toLocaleString("en-IN", {
                dateStyle: "medium",
                timeStyle: "short"
            });
        } catch (e) { return s; }
    }

    /* ---------- Status tabs ---------- */
    tabs.forEach(tab => {
        tab.addEventListener("click", function () {
            if (statusIn) statusIn.value = this.dataset.status;
            filterForm.submit();
        });
    });

    /* ---------- Delivery toggle ---------- */
    document.addEventListener("click", function (e) {
        const btn = e.target.closest(".js-toggle");
        if (!btn) return;

        const row = btn.closest("tr");
        if (row && row.dataset.locked === "1") {
            showToast("Delivery is locked (>5 hours). Cannot change.", "error");
            return;
        }

        const orderId = btn.dataset.id;
        const isEnabled = btn.dataset.enabled === "1";
        const newState = isEnabled ? "disabled" : "enabled";

        if (btn.disabled) return;
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<span class="btn-spinner"></span>';

        const fd = new FormData();
        fd.append("order_id", orderId);
        fd.append("delivery_status", newState);

        fetch(BASE_URL + "ajax/update-delivery-status.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                btn.disabled = false;

                if (!res || !res.success) {
                    showToast((res && res.message) || "Failed to update.", "error");
                    btn.innerHTML = original;
                    return;
                }

                const nowEnabled = newState === "enabled";
                showToast(nowEnabled ? "Marked as delivered." : "Delivery reverted.");

                const currentStatus = window.CURRENT_STATUS || "pending";

                if (
                    (currentStatus === "pending" && nowEnabled) ||
                    (currentStatus === "delivered" && !nowEnabled)
                ) {
                    if (row) {
                        row.style.transition = "opacity .25s ease";
                        row.style.opacity = "0";
                        setTimeout(() => {
                            row.remove();
                            if (tbody && tbody.children.length === 0) location.reload();
                        }, 260);
                    }
                } else {
                    btn.dataset.enabled = nowEnabled ? "1" : "0";
                    btn.classList.toggle("is-on", nowEnabled);
                    btn.innerHTML = nowEnabled
                        ? '<i class="bi bi-arrow-counterclockwise"></i> Undo'
                        : '<i class="bi bi-truck"></i> Delivered';
                    if (row) row.dataset.delivery = newState;
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = original;
                showToast("Unable to connect.", "error");
            });
    });

    /* ---------- Details modal ---------- */
    function openModal(orderId) {
        const o = ORDERS.find(x => String(x.id) === String(orderId));
        if (!o) return;

        amCode.textContent = "#" + o.order_code;
        amDate.textContent = formatDate(o.created_at);

        const isPaid = o.payment_status === "paid";
        const isEnabled = o.delivery_status === "enabled";
        const mobileClean = String(o.customer_mobile || "").replace(/[^0-9+]/g, "");

        const productsHtml = (o.products || []).map(p => {
            const thumb = p.image
                ? `<img src="${esc(p.image)}" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='📦';">`
                : '📦';

            const meta = [];
            if (p.variant_name) meta.push(esc(p.variant_name));
            if (p.variant_qty) meta.push(esc(p.variant_qty));
            meta.push("Qty " + (p.qty || 1));

            const containerLine = (Number(p.container_enabled) === 1 && Number(p.container_price) > 0)
                ? `<span style="display:inline-flex;align-items:center;gap:4px;color:#b8893c;background:#fdf7ec;border-radius:5px;padding:2px 6px;font-size:10px;font-weight:700;margin-top:4px;">
                       <i class="bi bi-box2-heart"></i> Container +₹${Number(p.container_price).toFixed(2)}
                   </span>`
                : '';

            return `
                <div class="ao-prod">
                    <div class="ao-prod-thumb">${thumb}</div>
                    <div class="ao-prod-info">
                        <p class="ao-prod-name">${esc(p.name || '')}</p>
                        <p class="ao-prod-meta">${meta.join(' · ')}</p>
                        ${containerLine}
                    </div>
                    <div class="ao-prod-price">${money(p.line_total || (p.price * p.qty))}</div>
                </div>
            `;
        }).join("");

        const containerSection = (o.total_containers > 0)
            ? `
                <div class="ao-modal-section">
                    <h4 class="ao-modal-title"><i class="bi bi-box2-heart"></i> Containers</h4>
                    <div class="ao-info-list">
                        <div class="ao-info-row">
                            <span class="lbl">Total Issued</span>
                            <span class="val">${o.total_containers}</span>
                        </div>
                        <div class="ao-info-row">
                            <span class="lbl">Returned</span>
                            <span class="val">${o.received_containers}</span>
                        </div>
                        <div class="ao-info-row">
                            <span class="lbl">Pending</span>
                            <span class="val" style="color:${o.pending_containers > 0 ? '#b8893c' : '#1f7a3d'};">${o.pending_containers}</span>
                        </div>
                        <div class="ao-info-row">
                            <span class="lbl">Deposit Held</span>
                            <span class="val">${money(o.container_amount)}</span>
                        </div>
                    </div>
                </div>
            `
            : '';

        amBody.innerHTML = `
            <div class="ao-modal-section">
                <h4 class="ao-modal-title"><i class="bi bi-person-badge-fill"></i> Customer</h4>
                <div class="ao-info-list">
                    <div class="ao-info-row">
                        <span class="lbl">Name</span>
                        <span class="val">${esc(o.customer_name)}</span>
                    </div>
                    <div class="ao-info-row">
                        <span class="lbl">Mobile</span>
                        <span class="val">
                            <a class="ao-call" href="tel:${esc(mobileClean)}">
                                <i class="bi bi-telephone-fill"></i> ${esc(o.customer_mobile)}
                            </a>
                        </span>
                    </div>
                </div>
            </div>

            <div class="ao-modal-section">
                <h4 class="ao-modal-title"><i class="bi bi-geo-alt-fill"></i> Delivery Address</h4>
                <div class="ao-info-list">
                    <div class="ao-info-row">
                        <span class="lbl">Apartment</span>
                        <span class="val">${esc(o.apartment_name || '—')}</span>
                    </div>
                    <div class="ao-info-row">
                        <span class="lbl">Code</span>
                        <span class="val">${esc(o.apartment_code || '—')}</span>
                    </div>
                    <div class="ao-info-row">
                        <span class="lbl">Division</span>
                        <span class="val">${esc(o.division || '—')}</span>
                    </div>
                </div>
            </div>

            <div class="ao-modal-section">
                <h4 class="ao-modal-title"><i class="bi bi-basket3-fill"></i> Items</h4>
                <div class="ao-products">
                    ${productsHtml || '<div style="padding:14px;text-align:center;color:#948c82;font-size:12px;">No items</div>'}
                </div>
            </div>

            ${containerSection}

            <div class="ao-modal-section">
                <h4 class="ao-modal-title"><i class="bi bi-receipt"></i> Payment Summary</h4>
                <div class="ao-totals">
                    <div class="ao-trow">
                        <span>Subtotal</span>
                        <strong>${money(o.subtotal)}</strong>
                    </div>
                    <div class="ao-trow">
                        <span>Delivery charge</span>
                        <strong>${money(o.division_charge)}</strong>
                    </div>
                    <div class="ao-trow grand">
                        <span>Total</span>
                        <strong>${money(o.total_amount)}</strong>
                    </div>
                </div>
            </div>

            <div class="ao-modal-section">
                <h4 class="ao-modal-title"><i class="bi bi-shield-check"></i> Status</h4>
                <div class="ao-info-list">
                    <div class="ao-info-row">
                        <span class="lbl">Payment</span>
                        <span class="val">
                            <span class="ao-pill ${isPaid ? 'is-paid' : 'is-unpaid'}">
                                <i class="bi ${isPaid ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>
                                ${isPaid ? 'Paid' : 'Unpaid'}
                            </span>
                        </span>
                    </div>
                    <div class="ao-info-row">
                        <span class="lbl">Delivery</span>
                        <span class="val">
                            <span class="ao-pill ${isEnabled ? 'is-enabled' : 'is-disabled'}">
                                <i class="bi ${isEnabled ? 'bi-truck' : 'bi-hourglass-split'}"></i>
                                ${isEnabled ? 'Delivered' : 'Pending'}
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        `;

        modal.classList.add("show");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        modal.classList.remove("show");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    document.addEventListener("click", function (e) {
        const viewBtn = e.target.closest(".js-view");
        if (viewBtn) openModal(viewBtn.dataset.id);
    });

    if (amClose) amClose.addEventListener("click", closeModal);
    if (modal) modal.addEventListener("click", e => {
        if (e.target === modal) closeModal();
    });

    /* =====================================================
       CONTAINER MODAL
       ===================================================== */
    function openContainerModal(mobile) {
        const summary = SUMMARY[mobile];
        if (!summary || summary.pending_containers <= 0) {
            showToast("No pending containers for this customer.", "error");
            return;
        }

        const orders = ORDERS.filter(o =>
            o.customer_mobile === mobile &&
            Number(o.pending_containers) > 0
        );

        editingContainer = {
            mobile: mobile,
            customer_name: summary.customer_name,
            summary: summary,
            orders: orders
        };

        containerOrderId.value = "";
        containerMobile.value = mobile;
        containerCount.value = "";
        containerNote.value = "";
        containerCount.max = summary.pending_containers;

        containerModalSub.textContent = summary.customer_name + " · " + mobile;

        const unitAmt = summary.total_containers > 0
            ? (summary.container_amount / summary.total_containers)
            : 0;

        containerModalInfo.innerHTML = `
            <div class="ao-info-row">
                <span class="lbl">Customer</span>
                <span class="val">${esc(summary.customer_name)}</span>
            </div>
            <div class="ao-info-row">
                <span class="lbl">Mobile</span>
                <span class="val">${esc(mobile)}</span>
            </div>
            <div class="ao-info-row">
                <span class="lbl">Orders with Containers</span>
                <span class="val">${summary.order_count}</span>
            </div>
            <div class="ao-info-row">
                <span class="lbl">Total Containers</span>
                <span class="val">${summary.total_containers}</span>
            </div>
            <div class="ao-info-row">
                <span class="lbl">Already Returned</span>
                <span class="val">${summary.received_containers}</span>
            </div>
            <div class="ao-info-row">
                <span class="lbl">Pending</span>
                <span class="val" style="color:#b8893c;font-weight:800;">${summary.pending_containers}</span>
            </div>
            <div class="ao-info-row">
                <span class="lbl">Refund per Container</span>
                <span class="val">${money(unitAmt)}</span>
            </div>
        `;

        if (orders.length > 0) {
            containerBreakdown.innerHTML = `
                <div style="font-size:11px;font-weight:800;color:#948c82;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">
                    Breakdown by Order (oldest first)
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    ${orders
                    .sort((a, b) => a.id - b.id)
                    .map(o => `
                            <div style="display:flex;justify-content:space-between;gap:12px;background:#fff;border:1px solid #f0ebe4;border-radius:9px;padding:10px 12px;font-size:12px;">
                                <div>
                                    <div style="font-weight:800;color:#b51f2c;">#${esc(o.order_code)}</div>
                                    <div style="font-size:10.5px;color:#948c82;margin-top:2px;">${esc(formatDate(o.created_at))}</div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-weight:800;color:#b8893c;">${o.pending_containers} pending</div>
                                    <div style="font-size:10.5px;color:#948c82;margin-top:2px;">${money(o.pending_containers * unitAmt)} refund</div>
                                </div>
                            </div>
                        `).join("")}
                </div>
            `;
        } else {
            containerBreakdown.innerHTML = "";
        }

        updateContainerHint();

        containerModal.classList.add("show");
        containerModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        setTimeout(() => containerCount.focus(), 100);
    }

    function closeContainerModal() {
        containerModal.classList.remove("show");
        containerModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        editingContainer = null;
        containerSubmit.disabled = false;
        containerSubmitText.textContent = "Mark Returned";
    }

    document.addEventListener("click", function (e) {
        const btn = e.target.closest(".js-container");
        if (btn) openContainerModal(btn.dataset.mobile);
    });

    if (containerModalClose) containerModalClose.addEventListener("click", closeContainerModal);
    if (containerCancel) containerCancel.addEventListener("click", closeContainerModal);
    if (containerModal) containerModal.addEventListener("click", e => {
        if (e.target === containerModal) closeContainerModal();
    });

    document.querySelectorAll("#aoContainerModal .ao-quick-btn[data-qty]").forEach(b => {
        b.addEventListener("click", () => {
            containerCount.value = b.dataset.qty;
            updateContainerHint();
        });
    });

    if (containerFullBtn) {
        containerFullBtn.addEventListener("click", () => {
            if (!editingContainer) return;
            containerCount.value = editingContainer.summary.pending_containers;
            updateContainerHint();
        });
    }

    containerCount?.addEventListener("input", updateContainerHint);

    function updateContainerHint() {
        if (!editingContainer) return;
        const v = parseInt(containerCount.value || "0", 10);
        const pending = Number(editingContainer.summary.pending_containers);
        const unitAmt = editingContainer.summary.total_containers > 0
            ? (editingContainer.summary.container_amount / editingContainer.summary.total_containers)
            : 0;

        if (!v || v <= 0) {
            containerQtyHint.textContent = "Enter a number between 1 and " + pending + ".";
            containerQtyHint.className = "hint";
            containerRefund.textContent = "₹0";
            return;
        }

        if (v > pending) {
            containerQtyHint.textContent = "Too many — max " + pending + ".";
            containerQtyHint.className = "hint red";
            containerRefund.textContent = money(pending * unitAmt);
            return;
        }

        containerQtyHint.textContent = "Will refund " + money(v * unitAmt) + " to wallet.";
        containerQtyHint.className = "hint green";
        containerRefund.textContent = money(v * unitAmt);
    }

    if (containerForm) {
        containerForm.addEventListener("submit", function (e) {
            e.preventDefault();
            if (!editingContainer) return;

            const v = parseInt(containerCount.value || "0", 10);
            const pending = Number(editingContainer.summary.pending_containers);

            if (!v || v <= 0) { showToast("Enter a valid count.", "error"); return; }
            if (v > pending) { showToast("Cannot exceed " + pending + ".", "error"); return; }

            containerSubmit.disabled = true;
            containerSubmitText.innerHTML = '<span class="btn-spinner"></span> Saving...';

            const fd = new FormData();
            fd.append("customer_mobile", editingContainer.mobile);
            fd.append("received_containers", v);
            fd.append("note", containerNote.value.trim());

            fetch(BASE_URL + "ajax/order-container-return.php", {
                method: "POST", body: fd, credentials: "same-origin"
            })
                .then(r => r.json().catch(() => null))
                .then(res => {
                    containerSubmit.disabled = false;
                    containerSubmitText.textContent = "Mark Returned";

                    if (!res || !res.success) {
                        showToast((res && res.message) || "Failed.", "error");
                        return;
                    }

                    showToast(res.message || "Container returned.");
                    closeContainerModal();
                    setTimeout(() => location.reload(), 600);
                })
                .catch(() => {
                    containerSubmit.disabled = false;
                    containerSubmitText.textContent = "Mark Returned";
                    showToast("Unable to connect.", "error");
                });
        });
    }

    /* =====================================================
       WALLET MODAL — same as payment wallet page
       ===================================================== */
    function openWalletModal(mobile, name) {
        if (!mobile) return;

        walletMobileInp.value = mobile;
        walletModalSub.textContent = (name || "Customer") + " · " + mobile;

        walletModalInfo.innerHTML = `
            <div class="ao-info-row">
                <span class="lbl">Customer</span>
                <span class="val">${esc(name || "—")}</span>
            </div>
            <div class="ao-info-row">
                <span class="lbl">Mobile</span>
                <span class="val">${esc(mobile)}</span>
            </div>
        `;

        walletBalanceEl.textContent = "…";
        walletStatusLbl.textContent = "Loading…";
        walletAmount.value = "";
        walletNote.value = "";
        walletPreviewEl.textContent = "₹0";
        setWalletTab("credit");

        walletHistory.innerHTML = `
            <div style="padding:24px;text-align:center;color:#948c82;font-size:11.5px;">
                Loading…
            </div>`;

        walletModal.classList.add("show");
        walletModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        setTimeout(() => walletAmount.focus(), 100);

        loadWalletCustomer(mobile);
    }

    function closeWalletModal() {
        walletModal.classList.remove("show");
        walletModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        walletCustomer = null;
        walletSubmit.disabled = false;
        walletSubmitText.textContent = "Add Money";
    }

    function loadWalletCustomer(mobile) {
        fetch(BASE_URL + "ajax/wallet-lookup.php?mobile=" + encodeURIComponent(mobile), {
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                if (!res || !res.success || !res.data || !res.data.found) {
                    walletBalanceEl.textContent = "₹0";
                    walletStatusLbl.textContent = "Not found";
                    walletCustomer = null;
                    return;
                }
                walletCustomer = {
                    id: res.data.id || 0,
                    name: res.data.name || "",
                    mobile: res.data.mobile || mobile,
                    wallet_balance: Number(res.data.wallet_balance || 0)
                };
                walletBalanceEl.textContent = money(walletCustomer.wallet_balance);
                walletStatusLbl.textContent = Number(res.data.status) === 1 ? "Active" : "Inactive";
                updateWalletPreview();
                loadWalletHistory(mobile);
            })
            .catch(() => {
                walletBalanceEl.textContent = "₹0";
                walletStatusLbl.textContent = "Error";
            });
    }

    function setWalletTab(tab) {
        walletActiveTab = tab;
        walletTxnType.value = tab;
        walletTabs.forEach(t => t.classList.toggle("active", t.dataset.wtab === tab));
        walletSubmitText.textContent = tab === "credit" ? "Add Money" : "Deduct Money";
        updateWalletPreview();
    }

    walletTabs.forEach(t => {
        t.addEventListener("click", () => setWalletTab(t.dataset.wtab));
    });

    function updateWalletPreview() {
        if (!walletCustomer) {
            walletPreviewEl.textContent = "₹0";
            return;
        }
        const amt = parseFloat(walletAmount.value || "0") || 0;
        const cur = Number(walletCustomer.wallet_balance) || 0;
        let next = walletActiveTab === "credit" ? cur + amt : cur - amt;
        if (next < 0) next = 0;
        walletPreviewEl.textContent = money(next);
        walletPreviewEl.style.color = (walletActiveTab === "debit" && amt > cur) ? "#b51f2c" : "#1f7a3d";
    }

    walletAmount?.addEventListener("input", updateWalletPreview);

    document.querySelectorAll("#aoWalletModal .ao-quick-btn[data-wamt]").forEach(b => {
        b.addEventListener("click", () => {
            walletAmount.value = b.dataset.wamt;
            updateWalletPreview();
        });
    });

    function submitWallet() {
        if (!walletCustomer || !walletCustomer.id) {
            showToast("Customer wallet not loaded.", "error");
            return;
        }

        const amt = parseFloat(walletAmount.value || "0");
        const note = walletNote.value.trim();

        if (!amt || amt <= 0) {
            showToast("Enter a valid amount.", "error");
            return;
        }

        if (walletActiveTab === "debit" && amt > Number(walletCustomer.wallet_balance)) {
            showToast("Insufficient balance. Available: " + money(walletCustomer.wallet_balance), "error");
            return;
        }

        walletSubmit.disabled = true;
        walletSubmitText.innerHTML = '<span class="btn-spinner"></span> Saving…';

        const fd = new FormData();
        fd.append("customer_id", walletCustomer.id);
        fd.append("txn_type", walletActiveTab);
        fd.append("amount", amt);
        fd.append("note", note);

        fetch(BASE_URL + "ajax/wallet-update.php", {
            method: "POST", body: fd, credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                walletSubmit.disabled = false;
                walletSubmitText.textContent = walletActiveTab === "credit" ? "Add Money" : "Deduct Money";

                if (!res || !res.success) {
                    showToast((res && res.message) || "Failed.", "error");
                    return;
                }

                showToast(res.message || "Wallet updated.");

                if (res.data && typeof res.data.balance_after !== "undefined") {
                    walletCustomer.wallet_balance = Number(res.data.balance_after);
                    walletBalanceEl.textContent = money(walletCustomer.wallet_balance);
                }

                walletAmount.value = "";
                walletNote.value = "";
                updateWalletPreview();
                loadWalletHistory(walletCustomer.mobile);
            })
            .catch(() => {
                walletSubmit.disabled = false;
                walletSubmitText.textContent = walletActiveTab === "credit" ? "Add Money" : "Deduct Money";
                showToast("Unable to connect.", "error");
            });
    }

    if (walletForm) {
        walletForm.addEventListener("submit", function (e) {
            e.preventDefault();
            submitWallet();
        });
    }

    function loadWalletHistory(mobile) {
        if (!walletHistory || !mobile) return;

        walletHistory.innerHTML = `
            <div style="padding:24px;text-align:center;color:#948c82;font-size:11.5px;">
                Loading…
            </div>`;

        fetch(BASE_URL + "ajax/wallet-history.php?mobile=" + encodeURIComponent(mobile), {
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                if (!res || !res.success) {
                    walletHistory.innerHTML = `<div style="padding:24px;text-align:center;color:#948c82;font-size:11.5px;">Failed to load.</div>`;
                    return;
                }
                const rows = Array.isArray(res.data) ? res.data : (res.data && res.data.transactions) || [];
                if (!rows.length) {
                    walletHistory.innerHTML = `<div style="padding:24px;text-align:center;color:#948c82;font-size:11.5px;">No transactions yet.</div>`;
                    return;
                }
                walletHistory.innerHTML = `
                    <table class="ao-history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map(t => {
                    const isCredit = t.txn_type === "credit";
                    return `
                                    <tr>
                                        <td>
                                            <div style="font-weight:700;color:#302923;">${esc(formatDate(t.created_at))}</div>
                                            <div style="font-size:9.5px;color:#948c82;margin-top:2px;">${esc(t.txn_code || "")}</div>
                                        </td>
                                        <td>
                                            <span class="ao-badge ${isCredit ? 'credit' : 'debit'}">
                                                ${isCredit ? "Credit" : "Debit"}
                                            </span>
                                        </td>
                                        <td style="text-align:right;" class="${isCredit ? 'ao-amt-credit' : 'ao-amt-debit'}">
                                            ${isCredit ? "+" : "−"} ${money(t.amount)}
                                        </td>
                                        <td style="text-align:right;" class="ao-bal-cell">
                                            ${money(t.balance_after)}
                                        </td>
                                    </tr>
                                `;
                }).join("")}
                        </tbody>
                    </table>
                `;
            })
            .catch(() => {
                walletHistory.innerHTML = `<div style="padding:24px;text-align:center;color:#948c82;font-size:11.5px;">Unable to connect.</div>`;
            });
    }

    walletRefreshH?.addEventListener("click", () => {
        if (walletCustomer && walletCustomer.mobile) loadWalletHistory(walletCustomer.mobile);
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest(".js-wallet");
        if (!btn) return;
        openWalletModal(btn.dataset.mobile, btn.dataset.name || "");
    });

    if (walletModalClose) walletModalClose.addEventListener("click", closeWalletModal);
    if (walletCancel) walletCancel.addEventListener("click", closeWalletModal);
    if (walletModal) walletModal.addEventListener("click", e => {
        if (e.target === walletModal) closeWalletModal();
    });

    /* ---------- Esc key ---------- */
    document.addEventListener("keydown", e => {
        if (e.key !== "Escape") return;
        if (modal.classList.contains("show")) closeModal();
        if (containerModal.classList.contains("show")) closeContainerModal();
        if (walletModal && walletModal.classList.contains("show")) closeWalletModal();
    });

})();