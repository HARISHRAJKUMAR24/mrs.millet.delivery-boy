/* =========================================================
   MRS MILL@ — APARTMENT ORDERS (delivery boy)
   File: ./js/apartment-orders.js
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL) ? window.BASE_URL : "./";
    const ORDERS   = Array.isArray(window.ORDERS) ? window.ORDERS : [];

    const tbody    = document.getElementById("aoBody");
    const toast    = document.getElementById("aoToast");
    const tabs     = document.querySelectorAll(".ao-tab");
    const statusIn = document.getElementById("statusInput");
    const dateIn   = document.getElementById("dateInput");
    const filterForm = document.getElementById("filterForm");

    const modal    = document.getElementById("aoModal");
    const amCode   = document.getElementById("amCode");
    const amDate   = document.getElementById("amDate");
    const amBody   = document.getElementById("amBody");
    const amClose  = document.getElementById("amClose");

    /* ---------- Helpers ---------- */
    function showToast(msg, type) {
        if (!toast) return;
        toast.textContent = msg;
        toast.className = "ao-toast show" + (type === "error" ? " error" : "");
        clearTimeout(toast._t);
        toast._t = setTimeout(() => (toast.className = "ao-toast"), 2400);
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

    /* ---------- Status tabs → submit form ---------- */
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

                /* If currently in "pending" tab and order just got delivered,
                   remove it from view. Same for "delivered" tab → undo. */
                const currentStatus = window.CURRENT_STATUS || "pending";
                const row = btn.closest("tr");

                if (
                    (currentStatus === "pending" && nowEnabled) ||
                    (currentStatus === "delivered" && !nowEnabled)
                ) {
                    if (row) {
                        row.style.transition = "opacity .25s ease";
                        row.style.opacity = "0";
                        setTimeout(() => {
                            row.remove();
                            if (tbody && tbody.children.length === 0) {
                                /* Reload so the empty state shows */
                                location.reload();
                            }
                        }, 260);
                    }
                } else {
                    /* Otherwise just flip the button state in place */
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

    /* ---------- Eye → details modal ---------- */
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
    document.addEventListener("keydown", e => {
        if (e.key === "Escape" && modal.classList.contains("show")) closeModal();
    });

})();