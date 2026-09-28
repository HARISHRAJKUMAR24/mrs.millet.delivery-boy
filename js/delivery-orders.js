/* =========================================================
   MRS MILL@ — DELIVERY ORDERS PAGE
   File: ./js/delivery-orders.js
   - Filter tabs (All / Pending / Delivered)
   - Delivery toggle button
   - Eye icon → details modal
   - Toast notifications
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL) ? window.BASE_URL : "./";
    const ORDERS   = Array.isArray(window.ORDERS) ? window.ORDERS : [];

    /* ---------- DOM ---------- */
    const tbody    = document.getElementById("doBody");
    const tabs     = document.querySelectorAll(".do-tab");
    const toast    = document.getElementById("doToast");

    const modal    = document.getElementById("doModal");
    const dmCode   = document.getElementById("dmCode");
    const dmDate   = document.getElementById("dmDate");
    const dmBody   = document.getElementById("dmBody");
    const dmClose  = document.getElementById("dmClose");

    /* =========================================================
       HELPERS
       ========================================================= */
    function showToast(msg, type) {
        if (!toast) return;
        toast.textContent = msg;
        toast.className = "do-toast show" + (type === "error" ? " error" : "");
        clearTimeout(toast._t);
        toast._t = setTimeout(() => (toast.className = "do-toast"), 2400);
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
            return new Date(s).toLocaleString("en-IN", {
                dateStyle: "medium",
                timeStyle: "short"
            });
        } catch (e) {
            return s;
        }
    }

    /* =========================================================
       FILTER TABS
       ========================================================= */
    tabs.forEach(tab => {
        tab.addEventListener("click", function () {
            tabs.forEach(t => t.classList.remove("active"));
            this.classList.add("active");

            const filter = this.dataset.filter;

            document.querySelectorAll("#doBody tr").forEach(row => {
                const state = row.dataset.delivery;
                row.style.display = (filter === "all" || state === filter) ? "" : "none";
            });
        });
    });

    /* =========================================================
       TOGGLE DELIVERY STATUS
       ========================================================= */
    document.addEventListener("click", function (e) {
        const btn = e.target.closest(".js-toggle");
        if (!btn) return;

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

                btn.dataset.enabled = nowEnabled ? "1" : "0";
                btn.classList.toggle("is-on", nowEnabled);
                btn.innerHTML = nowEnabled
                    ? '<i class="bi bi-arrow-counterclockwise"></i> Undo'
                    : '<i class="bi bi-truck"></i> Delivered';

                const row = btn.closest("tr");
                if (row) row.dataset.delivery = newState;

                showToast(nowEnabled ? "Marked as delivered." : "Delivery reverted.");
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = original;
                showToast("Unable to connect.", "error");
            });
    });

    /* =========================================================
       EYE ICON → OPEN DETAILS MODAL
       ========================================================= */
    function openModal(orderId) {
        const o = ORDERS.find(x => String(x.id) === String(orderId));
        if (!o) return;

        dmCode.textContent = "#" + o.order_code;
        dmDate.textContent = formatDate(o.created_at);

        const isPaid      = o.payment_status === "paid";
        const isEnabled   = o.delivery_status === "enabled";
        const isCancelled = o.status === "cancelled";
        const mobileClean = String(o.customer_mobile || "").replace(/[^0-9+]/g, "");

        /* ---- Products ---- */
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
                <div class="do-prod">
                    <div class="do-prod-thumb">${thumb}</div>
                    <div class="do-prod-info">
                        <p class="do-prod-name">${esc(p.name || '')}</p>
                        <p class="do-prod-meta">${meta.join(' · ')}</p>
                        ${containerLine}
                    </div>
                    <div class="do-prod-price">${money(p.line_total || (p.price * p.qty))}</div>
                </div>
            `;
        }).join("");

        dmBody.innerHTML = `
            <!-- Customer -->
            <div class="do-modal-section">
                <h4 class="do-modal-title"><i class="bi bi-person-badge-fill"></i> Customer</h4>
                <div class="do-info-list">
                    <div class="do-info-row">
                        <span class="lbl">Name</span>
                        <span class="val">${esc(o.customer_name)}</span>
                    </div>
                    <div class="do-info-row">
                        <span class="lbl">Mobile</span>
                        <span class="val">
                            <a class="do-call" href="tel:${esc(mobileClean)}">
                                <i class="bi bi-telephone-fill"></i> ${esc(o.customer_mobile)}
                            </a>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="do-modal-section">
                <h4 class="do-modal-title"><i class="bi bi-geo-alt-fill"></i> Delivery Address</h4>
                <div class="do-info-list">
                    <div class="do-info-row">
                        <span class="lbl">Apartment</span>
                        <span class="val">${esc(o.apartment_name || '—')}</span>
                    </div>
                    <div class="do-info-row">
                        <span class="lbl">Code</span>
                        <span class="val mono">${esc(o.apartment_code || '—')}</span>
                    </div>
                    <div class="do-info-row">
                        <span class="lbl">Division</span>
                        <span class="val">${esc(o.division || '—')}</span>
                    </div>
                </div>
            </div>

            <!-- Products -->
            <div class="do-modal-section">
                <h4 class="do-modal-title"><i class="bi bi-basket3-fill"></i> Items</h4>
                <div class="do-products">
                    ${productsHtml || '<div style="padding:14px;text-align:center;color:#948c82;font-size:12px;">No items</div>'}
                </div>
            </div>

            <!-- Totals -->
            <div class="do-modal-section">
                <h4 class="do-modal-title"><i class="bi bi-receipt"></i> Payment Summary</h4>
                <div class="do-totals">
                    <div class="do-trow">
                        <span>Subtotal</span>
                        <strong>${money(o.subtotal)}</strong>
                    </div>
                    <div class="do-trow">
                        <span>Delivery charge</span>
                        <strong>${money(o.division_charge)}</strong>
                    </div>
                    <div class="do-trow grand">
                        <span>Total</span>
                        <strong>${money(o.total_amount)}</strong>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div class="do-modal-section">
                <h4 class="do-modal-title"><i class="bi bi-shield-check"></i> Status</h4>
                <div class="do-info-list">
                    <div class="do-info-row">
                        <span class="lbl">Payment</span>
                        <span class="val">
                            <span class="do-pill ${isPaid ? 'is-paid' : 'is-unpaid'}">
                                <i class="bi ${isPaid ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>
                                ${isPaid ? 'Paid' : 'Unpaid'}
                            </span>
                        </span>
                    </div>
                    <div class="do-info-row">
                        <span class="lbl">Delivery</span>
                        <span class="val">
                            <span class="do-pill ${isEnabled ? 'is-enabled' : 'is-disabled'}">
                                <i class="bi ${isEnabled ? 'bi-truck' : 'bi-hourglass-split'}"></i>
                                ${isEnabled ? 'Delivered' : 'Pending'}
                            </span>
                        </span>
                    </div>
                    ${isCancelled ? `
                    <div class="do-info-row">
                        <span class="lbl">Order</span>
                        <span class="val"><span class="do-pill is-cancelled">Cancelled</span></span>
                    </div>` : ''}
                    ${o.payment_ref ? `
                    <div class="do-info-row">
                        <span class="lbl">Payment Ref</span>
                        <span class="val mono" style="font-size:11px;">${esc(o.payment_ref)}</span>
                    </div>` : ''}
                    ${o.paid_at ? `
                    <div class="do-info-row">
                        <span class="lbl">Paid At</span>
                        <span class="val" style="font-size:11.5px;">${esc(o.paid_at)}</span>
                    </div>` : ''}
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

    /* ---- Eye click ---- */
    document.addEventListener("click", function (e) {
        const viewBtn = e.target.closest(".js-view");
        if (!viewBtn) return;
        openModal(viewBtn.dataset.view || viewBtn.dataset.id);
    });

    /* ---- Close modal ---- */
    if (dmClose) dmClose.addEventListener("click", closeModal);
    if (modal) modal.addEventListener("click", function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && modal.classList.contains("show")) closeModal();
    });

    

})();