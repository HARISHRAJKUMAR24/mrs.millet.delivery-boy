

window.BASE_URL = "<?= BASE_URL ?>";

(function () {
    const sidebar = document.getElementById("sidebar");
    const toggle = document.getElementById("menuToggle");
    const closeBtn = document.getElementById("sidebarClose");
    const overlay = document.getElementById("sbOverlay");

    function openSidebar() {
        sidebar.classList.add("open");
        overlay.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeSidebar() {
        sidebar.classList.remove("open");
        overlay.classList.remove("show");
        document.body.style.overflow = "";
    }

    if (toggle) toggle.addEventListener("click", openSidebar);
    if (closeBtn) closeBtn.addEventListener("click", closeSidebar);
    if (overlay) overlay.addEventListener("click", closeSidebar);

    document.addEventListener("keydown", e => {
        if (e.key === "Escape" && sidebar.classList.contains("open")) {
            closeSidebar();
        }
    });

    document.querySelectorAll(".sb-nav a, .sb-logout a").forEach(link => {
        link.addEventListener("click", () => {
            if (window.innerWidth <= 860) closeSidebar();
        });
    });
})();

