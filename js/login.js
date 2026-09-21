/* =========================================================
   MRS MILL@ — DELIVERY BOY LOGIN
   File: ./js/login.js
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL)
        ? window.BASE_URL
        : "./";

    /* ---------------- DOM ---------------- */
    const form        = document.getElementById("loginForm");
    const mobileInput = document.getElementById("mobile_number");
    const pwdInput    = document.getElementById("password");
    const togglePwd   = document.getElementById("togglePwd");
    const eyeIcon     = document.getElementById("eyeIcon");
    const loginBtn    = document.getElementById("loginBtn");
    const loginBtnTxt = document.getElementById("loginBtnText");

    /* Popup */
    const popupOverlay = document.getElementById("popupOverlay");
    const popupIcon    = document.getElementById("popupIcon");
    const popupGlyph   = document.getElementById("popupIconGlyph");
    const popupTitle   = document.getElementById("popupTitle");
    const popupMsg     = document.getElementById("popupMsg");
    const popupBtn     = document.getElementById("popupBtn");

    if (!form) return;

    /* ---------------- HELPERS ---------------- */
    let popupCallback = null;
    let autoRedirectTimer = null;

    function setLoading(isLoading) {
        if (loginBtn)    loginBtn.disabled = isLoading;
        if (loginBtnTxt) loginBtnTxt.innerHTML = isLoading
            ? '<span class="btn-spinner"></span> Signing in...'
            : 'Sign In';
    }

    /* ---------------- CUSTOM POPUP ---------------- */
    function showPopup(type, title, message, btnText, onClose) {
        if (!popupOverlay) return;

        popupIcon.className  = "popup-icon " + type;
        popupGlyph.className = type === "success"
            ? "bi bi-check-lg"
            : "bi bi-exclamation-triangle-fill";

        popupTitle.textContent = title || (type === "success" ? "Success" : "Error");
        popupMsg.textContent   = message || "";

        popupBtn.className   = "popup-btn " + type;
        popupBtn.textContent = btnText || (type === "success" ? "Continue" : "Try Again");

        popupCallback = typeof onClose === "function" ? onClose : null;

        popupOverlay.classList.add("show");
        popupBtn.focus();
    }

    function closePopup() {
        if (!popupOverlay) return;
        popupOverlay.classList.remove("show");

        const cb = popupCallback;
        popupCallback = null;

        if (cb) setTimeout(cb, 220);
    }

    if (popupBtn) popupBtn.addEventListener("click", closePopup);

    if (popupOverlay) {
        popupOverlay.addEventListener("click", e => {
            if (e.target === popupOverlay) closePopup();
        });
    }

    document.addEventListener("keydown", e => {
        if (e.key === "Escape" && popupOverlay?.classList.contains("show")) {
            closePopup();
        }
    });

    /* ---------------- SHOW / HIDE PASSWORD ---------------- */
    if (togglePwd && pwdInput && eyeIcon) {
        togglePwd.addEventListener("click", () => {
            const isPwd = pwdInput.type === "password";
            pwdInput.type = isPwd ? "text" : "password";
            eyeIcon.className = isPwd ? "bi bi-eye-slash" : "bi bi-eye";
            togglePwd.setAttribute("aria-label", isPwd ? "Hide password" : "Show password");
        });
    }

    /* ---------------- DIGITS ONLY ---------------- */
    if (mobileInput) {
        mobileInput.addEventListener("input", () => {
            mobileInput.value = mobileInput.value.replace(/[^0-9]/g, "").slice(0, 15);
        });
    }

    /* ---------------- SUBMIT ---------------- */
    form.addEventListener("submit", e => {
        e.preventDefault();

        const mobile   = (mobileInput?.value || "").trim();
        const password = (pwdInput?.value    || "").trim();

        /* ---- Client-side validation via popup ---- */
        if (!mobile) {
            showPopup("error", "Mobile Required", "Please enter your mobile number.", "OK", () => mobileInput?.focus());
            return;
        }
        if (!/^[0-9]{10,15}$/.test(mobile)) {
            showPopup("error", "Invalid Mobile", "Mobile number must be 10–15 digits.", "OK", () => mobileInput?.focus());
            return;
        }
        if (!password) {
            showPopup("error", "Password Required", "Please enter your password.", "OK", () => pwdInput?.focus());
            return;
        }

        setLoading(true);

        const formData = new FormData();
        formData.append("mobile_number", mobile);
        formData.append("password", password);

        fetch(BASE_URL + "ajax/login.php", {
            method: "POST",
            body: formData,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => ({ success: false, message: "Unexpected server response." })))
            .then(data => {

                if (data.success) {
                    loginBtnTxt.innerHTML = '<i class="bi bi-check-lg"></i> Success!';

                    const redirect = (data.data && data.data.redirect)
                        ? data.data.redirect
                        : (BASE_URL + "index.php");

                    // ✅ SUCCESS POPUP — AUTO REDIRECT after 1.8s
                    showPopup(
                        "success",
                        "Login Successful",
                        (data.message || "Welcome back!") + " Redirecting to your dashboard…",
                        "Go Now",
                        () => { window.location.href = redirect; }   // if user clicks button
                    );

                    // ✅ AUTO REDIRECT — no click needed
                    autoRedirectTimer = setTimeout(() => {
                        window.location.href = redirect;
                    }, 1800);

                } else {
                    const msg = data.message || "Invalid mobile or password.";
                    const isInactive = /inactive|not\s*approv/i.test(msg);

                    showPopup(
                        "error",
                        isInactive ? "Account Not Approved" : "Login Failed",
                        msg,
                        "Try Again",
                        () => {
                            setLoading(false);
                            pwdInput?.focus();
                            pwdInput?.select();
                        }
                    );
                }
            })
            .catch(() => {
                showPopup(
                    "error",
                    "Connection Error",
                    "Unable to reach the server. Please check your connection and try again.",
                    "Try Again",
                    () => { setLoading(false); }
                );
            });
    });

    /* ---------------- FORGOT PASSWORD ---------------- */
    const forgotLink = document.getElementById("forgotLink");
    if (forgotLink) {
        forgotLink.addEventListener("click", e => {
            e.preventDefault();
            showPopup(
                "error",
                "Reset Password",
                "Please contact the admin to reset your password."
            );
        });
    }

})();