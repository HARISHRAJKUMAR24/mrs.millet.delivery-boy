/* =========================================================
   MRS MILL@ — APARTMENT ORDERS (delivery boy)
   File: ./js/apartment-orders.js
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL) ? window.BASE_URL : "./";
    const ORDERS   = Array.isArray(window.ORDERS) ? window.ORDERS : [];
    const SUMMARY  = window.CONTAINER_SUMMARY || {};

    const tbody      = document.getElementById("aoBody");
    const toast      = document.getElementById("aoToast");
    const tabs       = document.querySelectorAll(".ao-tab");
    const statusIn   = document.getElementById("statusInput");
    const dateIn     = document.getElementById("dateInput");
    const filterForm = document.getElementById("filterForm");

    /* Details modal */
    const modal    = document.getElementById("aoModal");
    const amCode   = document.getElementById("amCode");
    const amDate   = document.getElementById("amDate");
    const amBody   = document.getElementById("amBody");
    const amClose  = document.getElementById("amClose");

    /* Container modal */
    const containerModal      = document.getElementById("aoContainerModal");
    const containerModalSub   = document.getElementById("containerModalSub");
    const containerModalInfo  = document.getElementById("containerModalInfo");
    const containerBreakdown  = document.getElementById("containerBreakdown");
    const containerModalClose = document.getElementById("containerModalClose");
    const containerForm       = document.getElementById("containerForm");
    const containerOrderId    = document.getElementById("containerOrderId");
    const containerMobile     = document.getElementById("containerMobile");
    const containerCount      = document.getElementById("containerCount");
    const containerNote       = document.getElementById("containerNote");
    const containerRefund     = document.getElementById("containerRefund");
    const containerQtyHint    = document.getElementById("containerQtyHint");
    const containerFullBtn    = document.getElementById("containerFullBtn");
    const containerCancel     = document.getElementById("containerCancel");
    const containerSubmit     = document.getElementById("containerSubmit");
    const containerSubmitText = document.getElementById("containerSubmitText");

    let editingContainer = null;

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

        const orderId   = btn.dataset.id;
        const isEnabled = btn.dataset.enabled === "1";
        const newState  = isEnabled ? "disabled" : "enabled";

        if (btn.disabled) return;
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML  = '<span class="btn-spinner"></span>';

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

    /* =====================================================
       PAY BUTTON — instant, no confirm dialog
       ===================================================== */
    document.addEventListener("click", function (e) {
        const btn = e.target.closest(".js-pay");
        if (!btn || btn.disabled) return;

        const orderId = btn.dataset.id;
        if (!orderId) return;

        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<span class="btn-spinner"></span>';

        const fd = new FormData();
        fd.append("order_id", orderId);

        fetch(BASE_URL + "ajax/order-mark-paid.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                if (!res || !res.success) {
                    showToast((res && res.message) || "Failed to mark paid.", "error");
                    btn.disabled = false;
                    btn.innerHTML = original;
                    return;
                }

                showToast(res.message || "Payment marked as paid.");
                setTimeout(() => location.reload(), 500);
            })
            .catch(() => {
                showToast("Unable to connect.", "error");
                btn.disabled = false;
                btn.innerHTML = original;
            });
    });

    /* ---------- Details modal ---------- */
    function openModal(orderId) {
        const o = ORDERS.find(x => String(x.id) === String(orderId));
        if (!o) return;

        amCode.textContent = "#" + o.order_code;
        amDate.textContent = formatDate(o.created_at);

        const isPaid      = o.payment_status === "paid";
        const isEnabled   = o.delivery_status === "enabled";
        const mobileClean = String(o.customer_mobile || "").replace(/[^0-9+]/g, "");

        const productsHtml = (o.products || []).map(p => {
            const thumb = p.image
                ? `<img src="${esc(p.image)}" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='📦';">`
                : '📦';

            const meta = [];
            if (p.variant_name) meta.push(esc(p.variant_name));
            if (p.variant_qty)  meta.push(esc(p.variant_qty));
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
    if (containerCancel)    containerCancel.addEventListener("click", closeContainerModal);
    if (containerModal)     containerModal.addEventListener("click", e => {
        if (e.target === containerModal) closeContainerModal();
    });

    document.querySelectorAll(".ao-quick-btn[data-qty]").forEach(b => {
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

    /* ---------- Esc key ---------- */
    document.addEventListener("keydown", e => {
        if (e.key !== "Escape") return;
        if (modal.classList.contains("show")) closeModal();
        if (containerModal.classList.contains("show")) closeContainerModal();
    });

})();