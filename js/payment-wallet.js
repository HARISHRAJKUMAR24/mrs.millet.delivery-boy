/* =========================================================
   MRS MILL@ — PAYMENT WALLET (delivery boy)
   File: ./js/payment-wallet.js
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL) ? window.BASE_URL : "./";

    const grid          = document.getElementById("pwGrid");
    const search        = document.getElementById("pwSearch");
    const refreshBtn    = document.getElementById("pwRefresh");

    const pagWrap       = document.getElementById("pwPagination");
    const pagInfo       = document.getElementById("pwPagInfo");
    const pagControls   = document.getElementById("pwPagControls");
    const perPageSelect = document.getElementById("pwPerPage");

    /* Modal */
    const modal          = document.getElementById("pwModal");
    const modalClose     = document.getElementById("pwModalClose");
    const modalAvatar    = document.getElementById("pwModalAvatar");
    const modalName      = document.getElementById("pwModalName");
    const modalMobile    = document.getElementById("pwModalMobile");
    const modalBalance   = document.getElementById("pwModalBalance");
    const modalStatus    = document.getElementById("pwModalStatus");

    const tabs           = document.querySelectorAll(".pw-tab");
    const form           = document.getElementById("pwForm");
    const txnTypeInput   = document.getElementById("pwTxnType");
    const custIdInput    = document.getElementById("pwCustomerId");
    const amountInput    = document.getElementById("pwAmount");
    const noteInput      = document.getElementById("pwNote");
    const presets        = document.querySelectorAll(".pw-preset");
    const preview        = document.getElementById("pwPreview");
    const previewValue   = document.getElementById("pwPreviewValue");
    const cancelBtn      = document.getElementById("pwCancel");
    const submitBtn      = document.getElementById("pwSubmit");
    const submitText     = document.getElementById("pwSubmitText");
    const refreshHistory = document.getElementById("pwRefreshHistory");
    const historyWrap    = document.getElementById("pwHistory");

    const toastWrap      = document.getElementById("pwToastWrap");

    /* STATE */
    const DEFAULT_PAGE_SIZE = 12;
    let allCustomers = [];
    let filtered     = [];
    let currentPage  = 1;
    let pageSize     = DEFAULT_PAGE_SIZE;
    let searchTerm   = "";
    let activeCustomer = null;
    let activeTab    = "credit";

    /* HELPERS */
    function money(n) {
        const v = Number(n) || 0;
        return "₹" + v.toFixed(2).replace(/\.00$/, "");
    }

    function esc(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;")
            .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }

    function initials(name) {
        name = String(name || "").trim();
        if (!name) return "?";
        const parts = name.split(/\s+/);
        if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
        return name.substring(0, 2).toUpperCase();
    }

    function fmtDate(s) {
        if (!s) return "—";
        try {
            const dt = new Date(s.replace(" ", "T"));
            if (isNaN(dt.getTime())) return s;
            return dt.toLocaleString("en-IN", {
                day: "2-digit", month: "short", year: "numeric",
                hour: "2-digit", minute: "2-digit"
            });
        } catch (e) { return s; }
    }

    /* TOAST */
    function showToast(type, message, timeout) {
        if (!toastWrap) return;
        const icons = { success: "bi-check-lg", error: "bi-x-lg", info: "bi-info-lg" };
        const el = document.createElement("div");
        el.className = "pw-toast " + type;
        el.innerHTML = `
            <div class="pw-toast-icon"><i class="bi ${icons[type] || icons.info}"></i></div>
            <div class="pw-toast-body">${esc(message)}</div>
            <button type="button" class="pw-toast-close"><i class="bi bi-x"></i></button>
        `;
        toastWrap.appendChild(el);
        requestAnimationFrame(() => el.classList.add("show"));
        const close = () => {
            el.classList.remove("show");
            setTimeout(() => el.remove(), 300);
        };
        el.querySelector(".pw-toast-close").addEventListener("click", close);
        setTimeout(close, timeout || 3200);
    }

    /* =========================================
       RENDER CARD (with apartment + division)
       ========================================= */
    function renderCard(c) {
        const wallet = Number(c.wallet_balance) || 0;
        const walletClass = wallet > 0 ? "" : "zero";

        const aptName = (c.apartment_name || "").trim();
        const divName = (c.division || "").trim();

        const aptLine = aptName
            ? `<div class="pw-address-row">
                   <i class="bi bi-building"></i>
                   <span><span class="lbl">Apartment:</span> ${esc(aptName)}</span>
               </div>`
            : `<div class="pw-address-row" style="color:#948c82;">
                   <i class="bi bi-building" style="color:#d5cbbd;"></i>
                   <span>No apartment assigned</span>
               </div>`;

        const divLine = divName
            ? `<div class="pw-address-row">
                   <i class="bi bi-grid-3x3-gap"></i>
                   <span><span class="lbl">Division:</span> ${esc(divName)}</span>
               </div>`
            : `<div class="pw-address-row" style="color:#948c82;">
                   <i class="bi bi-grid-3x3-gap" style="color:#d5cbbd;"></i>
                   <span>No division</span>
               </div>`;

        return `
            <div class="pw-cust" data-id="${c.id}">
                <div class="pw-cust-head">
                    <div class="pw-cust-avatar">${esc(initials(c.full_name))}</div>
                    <div class="pw-cust-info">
                        <div class="pw-cust-name">${esc(c.full_name)}</div>
                        <div class="pw-cust-mobile">${esc(c.mobile_number)}</div>
                    </div>
                </div>

                <div class="pw-address">
                    ${aptLine}
                    ${divLine}
                </div>

                <div class="pw-cust-foot">
                    <span class="pw-wallet ${walletClass}">
                        <i class="bi bi-wallet2"></i> ${money(wallet)}
                    </span>
                    <span class="pw-cust-go">
                        <i class="bi bi-arrow-right"></i>
                    </span>
                </div>
            </div>
        `;
    }

    /* =========================================
       RENDER PAGE
       ========================================= */
    function renderPage() {
        const total = filtered.length;

        if (total === 0) {
            grid.innerHTML = `
                <div class="pw-empty">
                    <i class="bi bi-people"></i>
                    <h3>No customers found</h3>
                    <p>Try a different search.</p>
                </div>`;
            if (pagWrap) pagWrap.style.display = "none";
            return;
        }

        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const start = (currentPage - 1) * pageSize;
        const end   = Math.min(start + pageSize, total);

        grid.innerHTML = filtered.slice(start, end).map(renderCard).join("");

        if (pagInfo) {
            pagInfo.innerHTML =
                'Showing <strong>' + (start + 1) + '</strong>–<strong>' + end +
                '</strong> of <strong>' + total + '</strong>';
        }

        renderPagControls(totalPages);
        if (pagWrap) pagWrap.style.display = "flex";
    }

    function renderPagControls(totalPages) {
        if (!pagControls) return;
        if (totalPages <= 1) { pagControls.innerHTML = ""; return; }

        const html = [];
        html.push('<button type="button" data-page="' + (currentPage - 1) + '"' +
            (currentPage === 1 ? ' disabled' : '') +
            '><i class="bi bi-chevron-left"></i></button>');

        const delta = 1;
        const pages = [];
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) {
                pages.push(i);
            }
        }
        let prev = 0;
        pages.forEach(p => {
            if (prev && p - prev > 1) html.push('<span class="ellipsis">…</span>');
            html.push('<button type="button" data-page="' + p + '"' +
                (p === currentPage ? ' class="active"' : '') +
                '>' + p + '</button>');
            prev = p;
        });

        html.push('<button type="button" data-page="' + (currentPage + 1) + '"' +
            (currentPage === totalPages ? ' disabled' : '') +
            '><i class="bi bi-chevron-right"></i></button>');

        pagControls.innerHTML = html.join("");
    }

    pagControls?.addEventListener("click", (e) => {
        const btn = e.target.closest("button[data-page]");
        if (!btn || btn.disabled) return;
        const page = Number(btn.dataset.page);
        if (!page || page === currentPage) return;
        currentPage = page;
        renderPage();
    });

    /* =========================================
       FILTER
       ========================================= */
    function applyFilter(resetPage) {
        const q = (searchTerm || "").trim().toLowerCase();

        if (!q) {
            filtered = allCustomers.slice();
        } else {
            filtered = allCustomers.filter(c => {
                const name = (c.full_name || "").toLowerCase();
                const mob  = (c.mobile_number || "").toLowerCase();
                const apt  = (c.apartment_name || "").toLowerCase();
                const div  = (c.division || "").toLowerCase();
                return name.indexOf(q) !== -1 ||
                       mob.indexOf(q) !== -1 ||
                       apt.indexOf(q) !== -1 ||
                       div.indexOf(q) !== -1;
            });
        }

        if (resetPage !== false) currentPage = 1;
        renderPage();
    }

    let searchTimer = null;
    search?.addEventListener("input", function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            searchTerm = this.value;
            applyFilter(true);
        }, 180);
    });

    if (perPageSelect) {
        perPageSelect.value = String(pageSize);
        perPageSelect.addEventListener("change", function () {
            const v = Number(this.value);
            if (!v || v <= 0) return;
            pageSize = v;
            currentPage = 1;
            renderPage();
        });
    }

    /* =========================================
       LOAD CUSTOMERS
       ========================================= */
    function loadCustomers() {
        grid.innerHTML = `
            <div class="pw-empty">
                <i class="bi bi-arrow-repeat" style="animation:pwSpin .9s linear infinite;"></i>
                <h3>Loading…</h3>
                <p>Fetching customers</p>
            </div>`;

        fetch(BASE_URL + "ajax/wallet-customers-list.php?_t=" + Date.now(), {
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                if (!res || !res.success || !Array.isArray(res.data)) {
                    grid.innerHTML = `
                        <div class="pw-empty">
                            <i class="bi bi-exclamation-triangle"></i>
                            <h3>Failed to load</h3>
                            <p>${esc((res && res.message) || "Please try again.")}</p>
                        </div>`;
                    return;
                }

                allCustomers = res.data;
                applyFilter(true);
            })
            .catch(() => {
                grid.innerHTML = `
                    <div class="pw-empty">
                        <i class="bi bi-wifi-off"></i>
                        <h3>Unable to connect</h3>
                        <p>Check your network.</p>
                    </div>`;
            });
    }

    refreshBtn?.addEventListener("click", function () {
        const icon = this.querySelector("i");
        if (icon) { icon.style.transition = "transform .5s"; icon.style.transform = "rotate(360deg)"; }
        setTimeout(() => { if (icon) icon.style.transform = "rotate(0deg)"; }, 500);
        loadCustomers();
    });

    /* =========================================
       CLICK CUSTOMER → OPEN MODAL
       ========================================= */
    grid?.addEventListener("click", (e) => {
        const card = e.target.closest(".pw-cust");
        if (!card) return;
        const id = Number(card.dataset.id);
        const customer = allCustomers.find(c => Number(c.id) === id);
        if (!customer) return;
        openModal(customer);
    });

    /* =========================================
       MODAL
       ========================================= */
    function openModal(customer) {
        activeCustomer = customer;

        custIdInput.value = customer.id;
        modalAvatar.textContent = initials(customer.full_name);
        modalName.textContent = customer.full_name;
        modalMobile.textContent = customer.mobile_number;
        modalBalance.textContent = money(customer.wallet_balance);
        modalStatus.textContent = Number(customer.status) === 1 ? "Active" : "Inactive";

        /* Reset */
        setTab("credit");
        amountInput.value = "";
        noteInput.value = "";
        updatePreview();

        modal.classList.add("show");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        setTimeout(() => amountInput.focus(), 100);

        loadHistory(customer.id);
    }

    function closeModal() {
        modal.classList.remove("show");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        activeCustomer = null;
        submitBtn.disabled = false;
    }

    modalClose?.addEventListener("click", closeModal);
    cancelBtn?.addEventListener("click", closeModal);
    modal?.addEventListener("click", (e) => {
        if (e.target === modal) closeModal();
    });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && modal.classList.contains("show")) closeModal();
    });

    /* Tabs */
    function setTab(tab) {
        activeTab = tab;
        txnTypeInput.value = tab;
        tabs.forEach(t => t.classList.toggle("active", t.dataset.tab === tab));
        submitText.textContent = tab === "credit" ? "Add Money" : "Deduct Money";
        updatePreview();
    }

    tabs.forEach(t => {
        t.addEventListener("click", () => setTab(t.dataset.tab));
    });

    /* Presets */
    presets.forEach(p => {
        p.addEventListener("click", () => {
            amountInput.value = p.dataset.amt;
            updatePreview();
        });
    });

    /* Preview */
    amountInput?.addEventListener("input", updatePreview);

    function updatePreview() {
        if (!activeCustomer) return;
        const amt = parseFloat(amountInput.value || "0") || 0;
        const current = Number(activeCustomer.wallet_balance) || 0;

        let next = current;
        let warn = false;

        if (activeTab === "credit") {
            next = current + amt;
        } else {
            next = current - amt;
            if (next < 0) { next = 0; warn = true; }
        }

        previewValue.textContent = money(next);
        preview.classList.toggle("warn", warn);
    }

    /* =========================================
       SUBMIT WALLET UPDATE
       ========================================= */
    form?.addEventListener("submit", (e) => {
        e.preventDefault();
        if (!activeCustomer) return;

        const amt = parseFloat(amountInput.value || "0");
        const note = noteInput.value.trim();

        if (!amt || amt <= 0) {
            showToast("error", "Enter a valid amount.");
            amountInput.focus();
            return;
        }

        if (activeTab === "debit") {
            const current = Number(activeCustomer.wallet_balance) || 0;
            if (amt > current) {
                showToast("error", "Insufficient balance. Available: " + money(current));
                return;
            }
        }

        submitBtn.disabled = true;
        submitText.innerHTML = '<span class="bi bi-hourglass-split"></span> Processing…';

        const fd = new FormData();
        fd.append("customer_id", activeCustomer.id);
        fd.append("txn_type", activeTab);
        fd.append("amount", amt);
        fd.append("note", note);

        fetch(BASE_URL + "ajax/wallet-update.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                submitBtn.disabled = false;
                submitText.textContent = activeTab === "credit" ? "Add Money" : "Deduct Money";

                if (!res || !res.success) {
                    showToast("error", (res && res.message) || "Failed to update wallet.");
                    return;
                }

                showToast("success", res.message || "Wallet updated.");

                /* Update local customer object */
                if (res.data && typeof res.data.balance_after !== "undefined") {
                    activeCustomer.wallet_balance = Number(res.data.balance_after);
                    modalBalance.textContent = money(res.data.balance_after);
                }

                /* Update card in list */
                const c = allCustomers.find(x => String(x.id) === String(activeCustomer.id));
                if (c) c.wallet_balance = activeCustomer.wallet_balance;
                renderPage();

                amountInput.value = "";
                noteInput.value = "";
                updatePreview();
                loadHistory(activeCustomer.id);
            })
            .catch(() => {
                submitBtn.disabled = false;
                submitText.textContent = activeTab === "credit" ? "Add Money" : "Deduct Money";
                showToast("error", "Unable to connect.");
            });
    });

    /* =========================================
       HISTORY
       ========================================= */
    refreshHistory?.addEventListener("click", () => {
        if (activeCustomer) loadHistory(activeCustomer.id);
    });

    function loadHistory(customerId) {
        if (!historyWrap) return;
        historyWrap.innerHTML = `
            <div style="padding:30px;text-align:center;color:#948c82;font-size:11.5px;">
                Loading transactions…
            </div>`;

        fetch(BASE_URL + "ajax/wallet-history.php?customer_id=" + encodeURIComponent(customerId), {
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                if (!res || !res.success) {
                    historyWrap.innerHTML = `
                        <div style="padding:30px;text-align:center;color:#948c82;font-size:11.5px;">
                            Failed to load history.
                        </div>`;
                    return;
                }

                const rows = Array.isArray(res.data) ? res.data : (res.data.transactions || []);

                if (!rows.length) {
                    historyWrap.innerHTML = `
                        <div style="padding:40px 20px;text-align:center;color:#948c82;font-size:11.5px;">
                            No transactions yet.
                        </div>`;
                    return;
                }

                historyWrap.innerHTML = `
                    <table class="pw-history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Note</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map(t => {
                                const isCredit = t.txn_type === "credit";
                                return `
                                    <tr>
                                        <td>
                                            <div style="font-weight:700;color:#302923;">${esc(fmtDate(t.created_at))}</div>
                                            <div style="font-size:9.5px;color:#948c82;margin-top:2px;">${esc(t.txn_code || "")}</div>
                                        </td>
                                        <td>
                                            <span class="pw-badge ${isCredit ? 'credit' : 'debit'}">
                                                <i class="bi bi-${isCredit ? 'arrow-down' : 'arrow-up'}"></i>
                                                ${isCredit ? 'Credit' : 'Debit'}
                                            </span>
                                        </td>
                                        <td class="${isCredit ? 'pw-amt-credit' : 'pw-amt-debit'}">
                                            ${isCredit ? '+' : '−'} ${money(t.amount)}
                                        </td>
                                        <td class="pw-bal-cell">${money(t.balance_after)}</td>
                                        <td>${esc(t.note || "—")}</td>
                                        <td>${esc(t.created_by_name || "—")}</td>
                                    </tr>
                                `;
                            }).join("")}
                        </tbody>
                    </table>
                `;
            })
            .catch(() => {
                historyWrap.innerHTML = `
                    <div style="padding:30px;text-align:center;color:#948c82;font-size:11.5px;">
                        Unable to connect.
                    </div>`;
            });
    }

    /* =========================================
       INIT
       ========================================= */
    loadCustomers();

})();