/* =========================================================
   MRS MILL@ — DELIVERY BOY REGISTER
   File: ./js/register.js
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL)
        ? window.BASE_URL
        : "./";

    /* ---------------- DOM ---------------- */
    const form       = document.getElementById("registerForm");
    const nameInput  = document.getElementById("full_name");
    const mobileInput = document.getElementById("mobile_number");
    const pwdInput   = document.getElementById("password");
    const pwd2Input  = document.getElementById("confirm_password");
    const togglePwd  = document.getElementById("togglePwd");
    const eyeIcon    = document.getElementById("eyeIcon");
    const togglePwd2 = document.getElementById("togglePwd2");
    const eyeIcon2   = document.getElementById("eyeIcon2");
    const registerBtn = document.getElementById("registerBtn");
    const registerBtnTxt = document.getElementById("registerBtnText");

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

    function setLoading(isLoading) {
        if (registerBtn)    registerBtn.disabled = isLoading;
        if (registerBtnTxt) registerBtnTxt.innerHTML = isLoading
            ? '<span class="btn-spinner"></span> Creating account...'
            : 'Create Account';
    }

    /* ---------------- CUSTOM POPUP ---------------- */
    // type: "success" | "error" | "info"
    function showPopup(type, title, message, btnText, onClose) {
        if (!popupOverlay) return;

        popupIcon.className  = "popup-icon " + type;
        popupGlyph.className = type === "success"
            ? "bi bi-check-lg"
            : (type === "info" ? "bi bi-hourglass-split" : "bi bi-exclamation-triangle-fill");

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
    function bindToggle(btn, input, icon) {
        if (!btn || !input || !icon) return;
        btn.addEventListener("click", () => {
            const isPwd = input.type === "password";
            input.type = isPwd ? "text" : "password";
            icon.className = isPwd ? "bi bi-eye-slash" : "bi bi-eye";
            btn.setAttribute("aria-label", isPwd ? "Hide password" : "Show password");
        });
    }

    bindToggle(togglePwd, pwdInput, eyeIcon);
    bindToggle(togglePwd2, pwd2Input, eyeIcon2);

    /* ---------------- DIGITS ONLY ---------------- */
    if (mobileInput) {
        mobileInput.addEventListener("input", () => {
            mobileInput.value = mobileInput.value.replace(/[^0-9]/g, "").slice(0, 15);
        });
    }

    /* ---------------- SUBMIT ---------------- */
    form.addEventListener("submit", e => {
        e.preventDefault();

        const full_name        = (nameInput?.value  || "").trim();
        const mobile           = (mobileInput?.value || "").trim();
        const password         = (pwdInput?.value    || "").trim();
        const confirm_password = (pwd2Input?.value   || "").trim();

        /* ---- Client-side validation via popup ---- */
        if (!full_name) {
            showPopup("error", "Name Required", "Please enter your full name.", "OK", () => nameInput?.focus());
            return;
        }
        if (full_name.length < 3) {
            showPopup("error", "Invalid Name", "Name must be at least 3 characters long.", "OK", () => nameInput?.focus());
            return;
        }
        if (!mobile) {
            showPopup("error", "Mobile Required", "Please enter your mobile number.", "OK", () => mobileInput?.focus());
            return;
        }
        if (!/^[0-9]{10,15}$/.test(mobile)) {
            showPopup("error", "Invalid Mobile", "Mobile number must be 10–15 digits.", "OK", () => mobileInput?.focus());
            return;
        }
        if (!password) {
            showPopup("error", "Password Required", "Please create a password.", "OK", () => pwdInput?.focus());
            return;
        }
        if (password.length < 6) {
            showPopup("error", "Weak Password", "Password must be at least 6 characters long.", "OK", () => pwdInput?.focus());
            return;
        }
        if (password !== confirm_password) {
            showPopup("error", "Password Mismatch", "Passwords do not match. Please try again.", "OK", () => {
                pwd2Input?.focus();
                pwd2Input?.select();
            });
            return;
        }

        setLoading(true);

        const formData = new FormData();
        formData.append("full_name", full_name);
        formData.append("mobile_number", mobile);
        formData.append("password", password);
        formData.append("confirm_password", confirm_password);

        fetch(BASE_URL + "ajax/register.php", {
            method: "POST",
            body: formData,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => ({ success: false, message: "Unexpected server response." })))
            .then(data => {

                if (data.success) {
                    registerBtnTxt.innerHTML = '<i class="bi bi-check-lg"></i> Done!';

                    // ✅ SUCCESS — waiting for admin approval
                    showPopup(
                        "success",
                        "Registration Successful",
                        data.message || "Your account has been created. Please wait for admin approval before logging in.",
                        "Go to Login",
                        () => { window.location.href = BASE_URL + "login.php"; }
                    );

                } else {
                    showPopup(
                        "error",
                        "Registration Failed",
                        data.message || "Unable to create account. Please try again.",
                        "Try Again",
                        () => {
                            setLoading(false);
                            mobileInput?.focus();
                            mobileInput?.select();
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

})();