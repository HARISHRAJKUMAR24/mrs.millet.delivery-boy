/* =========================================================
   MRS MILL@ — DELIVERY BOY "MY ORDERS"
   File: ./js/orders.js
   Client-side pagination + per-page + search + date filter
   Row click → order-view.php?id=X
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL)
        ? window.BASE_URL
        : "./";

    /* DOM */
    const tbody       = document.getElementById("orTbody");
    const searchInput = document.getElementById("orSearch");
    const tabs        = document.querySelectorAll(".or-tab");

    const dateSelect  = document.getElementById("orDateFilter");
    const dateFrom    = document.getElementById("orDateFrom");
    const dateTo      = document.getElementById("orDateTo");
    const dateCustom  = document.getElementById("orDateCustom");

    const paginationWrap     = document.getElementById("orPagination");
    const paginationInfo     = document.getElementById("paginationInfo");
    const paginationControls = document.getElementById("paginationControls");
    const perPageSelect      = document.getElementById("perPageSelect");

    if (!tbody) return;

    /* STATE */
    const DEFAULT_PAGE_SIZE = 10;

    let pageSize     = DEFAULT_PAGE_SIZE;
    let allRows      = [];
    let filteredRows = [];
    let currentPage  = 1;
    let activeFilter = "all";
    let activeDate   = "all";
    let searchTerm   = "";

    /* HELPERS */
    function money(n) {
        return "₹" + Math.round(Number(n) || 0);
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function formatDate(str) {
        if (!str) return "—";
        const d = new Date(str.replace(" ", "T"));
        if (isNaN(d.getTime())) return str;
        const dd  = String(d.getDate()).padStart(2, "0");
        const mon = d.toLocaleString("en-IN", { month: "short" });
        const yr  = d.getFullYear();
        const hh  = String(d.getHours()).padStart(2, "0");
        const mm  = String(d.getMinutes()).padStart(2, "0");
        return `${dd} ${mon} ${yr} · ${hh}:${mm}`;
    }

    /* BADGES */
    function orderStatusBadge(s) {
        const map = {
            pending:    "status-pending",
            confirmed:  "status-confirmed",
            processing: "status-processing",
            delivered:  "status-delivered",
            cancelled:  "status-cancelled"
        };
        return `<span class="order-status ${map[s] || "status-pending"}">${escapeHtml(s)}</span>`;
    }

    function payStatusBadge(s) {
        const map = {
            paid:   "pay-paid",
            unpaid: "pay-unpaid",
            failed: "pay-failed"
        };
        return `<span class="payment-status ${map[s] || "pay-unpaid"}">${escapeHtml(s)}</span>`;
    }

    function containerBadge(o) {
        const issued   = Number(o.container_issued || 0);
        const returned = Number(o.container_returned || 0);
        const balance  = issued - returned;

        if (issued <= 0) {
            return `<span class="order-status" style="background:#f4efe8;color:#6f5a3f;">—</span>`;
        }

        const cls = balance === 0 ? "status-delivered" : "status-pending";
        return `<span class="order-status ${cls}">${returned}/${issued}</span>`;
    }

    /* RENDER ROW */
    function renderRow(o) {
        const initial = (o.customer_name || "?").trim().charAt(0).toUpperCase();

        return `
            <tr data-id="${o.id}" data-href="${BASE_URL}order-view.php?id=${o.id}">
                <td>
                    <div class="order-cell">
                        <div class="order-avatar">${escapeHtml(initial)}</div>
                        <div class="order-info">
                            <div class="order-code">#${escapeHtml(o.order_code)}</div>
                            <div class="order-time">${escapeHtml(formatDate(o.created_at))}</div>
                        </div>
                    </div>
                </td>

                <td>
                    <div class="cust-cell">
                        <div class="cust-name">${escapeHtml(o.customer_name)}</div>
                        <div class="cust-meta">
                            <i class="bi bi-telephone-fill"></i>
                            ${escapeHtml(o.customer_mobile)}
                        </div>
                    </div>
                </td>

                <td>
                    <div class="cust-name">${escapeHtml(o.apartment_name || "—")}</div>
                    ${o.apartment_code ? `<div class="cust-meta">#${escapeHtml(o.apartment_code)}</div>` : ""}
                </td>

                <td>${orderStatusBadge(o.status)}</td>
                <td>${payStatusBadge(o.payment_status)}</td>
                <td>${containerBadge(o)}</td>
                <td style="text-align:right;">
                    <div class="amount-cell">${money(o.total_amount)}</div>
                </td>
            </tr>
        `;
    }

    /* PAGINATION */
    function renderPage() {
        const total = filteredRows.length;

        if (total === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7">
                        <div class="or-empty">
                            <i class="bi bi-inbox"></i>
                            <h3>No orders found</h3>
                            <p>Nothing matches your filter or search.</p>
                        </div>
                    </td>
                </tr>`;
            if (paginationWrap) paginationWrap.style.display = "none";
            return;
        }

        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const start = (currentPage - 1) * pageSize;
        const end   = Math.min(start + pageSize, total);
        const slice = filteredRows.slice(start, end);

        tbody.innerHTML = slice.map(renderRow).join("");

        if (paginationInfo) {
            paginationInfo.innerHTML =
                'Showing <strong>' + (start + 1) + '</strong>–<strong>' + end +
                '</strong> of <strong>' + total + '</strong>';
        }

        renderPaginationControls(totalPages);
        if (paginationWrap) paginationWrap.style.display = "flex";
    }

    function renderPaginationControls(totalPages) {
        if (!paginationControls) return;

        if (totalPages <= 1) {
            paginationControls.innerHTML = "";
            return;
        }

        const html = [];

        html.push(
            '<button type="button" data-page="' + (currentPage - 1) + '"' +
            (currentPage === 1 ? ' disabled' : '') +
            ' title="Previous"><i class="bi bi-chevron-left"></i></button>'
        );

        getPageList(currentPage, totalPages).forEach(function (p) {
            if (p === "...") {
                html.push('<span class="page-ellipsis">…</span>');
            } else {
                html.push(
                    '<button type="button" data-page="' + p + '"' +
                    (p === currentPage ? ' class="active"' : '') +
                    '>' + p + '</button>'
                );
            }
        });

        html.push(
            '<button type="button" data-page="' + (currentPage + 1) + '"' +
            (currentPage === totalPages ? ' disabled' : '') +
            ' title="Next"><i class="bi bi-chevron-right"></i></button>'
        );

        paginationControls.innerHTML = html.join("");
    }

    function getPageList(current, total) {
        const delta = 1;
        const range = [];
        const out   = [];

        for (let i = 1; i <= total; i++) {
            if (i === 1 || i === total ||
                (i >= current - delta && i <= current + delta)) {
                range.push(i);
            }
        }

        let prev = 0;
        range.forEach(function (i) {
            if (prev && i - prev > 1) out.push("...");
            out.push(i);
            prev = i;
        });

        return out;
    }

    if (paginationControls) {
        paginationControls.addEventListener("click", function (e) {
            const btn = e.target.closest("button[data-page]");
            if (!btn || btn.disabled) return;

            const page = Number(btn.dataset.page);
            if (!page || page === currentPage) return;

            currentPage = page;
            renderPage();

            const card = document.querySelector(".or-card");
            if (card) {
                window.scrollTo({
                    top: card.offsetTop - 20,
                    behavior: "smooth"
                });
            }
        });
    }

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

    /* FILTER (client-side search) */
    function applyFilterAndRender(resetPage) {
        const q = (searchTerm || "").trim().toLowerCase();

        if (!q) {
            filteredRows = allRows.slice();
        } else {
            filteredRows = allRows.filter(function (o) {
                const hay = [
                    o.order_code || "",
                    o.customer_name || "",
                    o.customer_mobile || "",
                    o.apartment_name || "",
                    o.apartment_code || ""
                ].join(" ").toLowerCase();
                return hay.indexOf(q) !== -1;
            });
        }

        if (resetPage !== false) currentPage = 1;
        renderPage();
    }

    /* LOAD */
    function loadOrders() {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center;padding:40px;color:#948c82;">
                    Loading orders...
                </td>
            </tr>`;
        if (paginationWrap) paginationWrap.style.display = "none";

        const params = new URLSearchParams();
        params.set("filter", activeFilter);
        params.set("date",   activeDate);
        params.set("q",      "");

        if (activeDate === "custom") {
            if (dateFrom && dateFrom.value) params.set("from", dateFrom.value);
            if (dateTo   && dateTo.value)   params.set("to",   dateTo.value);
        }

        const url = BASE_URL + "ajax/my-orders.php?" + params.toString();

        fetch(url, { credentials: "same-origin" })
            .then(r => r.json().catch(() => null))
            .then(res => {

                if (!res || !res.success || !Array.isArray(res.data)) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="7" style="text-align:center;padding:40px;color:#b51f2c;">
                                ${escapeHtml((res && res.message) || "Failed to load orders.")}
                            </td>
                        </tr>`;
                    return;
                }

                allRows = res.data;
                currentPage = 1;
                applyFilterAndRender();
            })
            .catch(() => {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align:center;padding:40px;color:#b51f2c;">
                            Unable to connect to server.
                        </td>
                    </tr>`;
            });
    }

    /* ROW CLICK — navigate */
    tbody.addEventListener("click", function (e) {
        const tr = e.target.closest("tr[data-id]");
        if (!tr) return;

        const href = tr.dataset.href;
        if (href) window.location.href = href;
    });

    /* FILTER TABS */
    tabs.forEach(tab => {
        tab.addEventListener("click", function () {
            tabs.forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            activeFilter = tab.dataset.filter || "all";
            currentPage = 1;
            loadOrders();
        });
    });

    /* DATE FILTER */
    if (dateSelect) {
        dateSelect.addEventListener("change", function () {
            activeDate = this.value || "all";

            if (dateCustom) {
                dateCustom.style.display = (activeDate === "custom") ? "inline-flex" : "none";
            }

            if (activeDate !== "custom" ||
                (dateFrom && dateFrom.value && dateTo && dateTo.value)) {
                currentPage = 1;
                loadOrders();
            }
        });
    }

    if (dateFrom) {
        dateFrom.addEventListener("change", function () {
            if (activeDate === "custom" && dateTo && dateTo.value) {
                currentPage = 1;
                loadOrders();
            }
        });
    }

    if (dateTo) {
        dateTo.addEventListener("change", function () {
            if (activeDate === "custom" && dateFrom && dateFrom.value) {
                currentPage = 1;
                loadOrders();
            }
        });
    }

    /* SEARCH */
    let searchTimer = null;
    if (searchInput) {
        searchInput.addEventListener("input", function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                searchTerm = searchInput.value;
                applyFilterAndRender();
            }, 200);
        });
    }

    /* INIT */
    loadOrders();

})();