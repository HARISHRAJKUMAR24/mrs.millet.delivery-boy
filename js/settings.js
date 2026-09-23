/* =========================================================
   MRS MILL@ — DELIVERY BOY SETTINGS
   File: ./js/settings.js
   - Update profile (name, mobile, email)
   - Change password (current + new + confirm)
   ========================================================= */

(function () {
    "use strict";

    const BASE_URL = (typeof window.BASE_URL === "string" && window.BASE_URL)
        ? window.BASE_URL
        : "./";

    /* DOM */
    const profileForm      = document.getElementById("profileForm");
    const saveProfileBtn   = document.getElementById("saveProfileBtn");
    const saveProfileText  = document.getElementById("saveProfileText");

    const nameInput        = document.getElementById("st_full_name");
    const mobileInput      = document.getElementById("st_mobile");
    const emailInput       = document.getElementById("st_email");

    const passwordForm     = document.getElementById("passwordForm");
    const savePasswordBtn  = document.getElementById("savePasswordBtn");
    const savePasswordText = document.getElementById("savePasswordText");

    const currentPwd       = document.getElementById("st_current_password");
    const newPwd           = document.getElementById("st_new_password");
    const confirmPwd       = document.getElementById("st_confirm_password");

    if (!profileForm) return;

    /* Toast */
    function showToast(msg, type) {
        let toast = document.getElementById("mmToast");
        if (!toast) {
            toast = document.createElement("div");
            toast.id = "mmToast";
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.className = "show" + (type === "error" ? " error" : "");
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => toast.className = "", 2600);
    }

    /* Show/hide password toggles */
    document.querySelectorAll(".st-eye-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const targetId = btn.getAttribute("data-toggle");
            const input    = document.getElementById(targetId);
            const icon     = btn.querySelector("i");
            if (!input || !icon) return;

            const isPwd = input.type === "password";
            input.type = isPwd ? "text" : "password";
            icon.className = isPwd ? "bi bi-eye-slash" : "bi bi-eye";
        });
    });

    /* Digits only for mobile */
    if (mobileInput) {
        mobileInput.addEventListener("input", function () {
            mobileInput.value = mobileInput.value.replace(/[^0-9]/g, "").slice(0, 15);
        });
    }

    /* ---------- SAVE PROFILE ---------- */
    profileForm.addEventListener("submit", function (e) {
        e.preventDefault();

        const name   = (nameInput.value || "").trim();
        const mobile = (mobileInput.value || "").trim();
        const email  = (emailInput.value || "").trim();

        if (!name) {
            showToast("Please enter your full name.", "error");
            nameInput.focus();
            return;
        }

        if (name.length < 3) {
            showToast("Name must be at least 3 characters.", "error");
            nameInput.focus();
            return;
        }

        if (!mobile) {
            showToast("Please enter your mobile number.", "error");
            mobileInput.focus();
            return;
        }

        if (!/^[0-9]{10,15}$/.test(mobile)) {
            showToast("Mobile number must be 10–15 digits.", "error");
            mobileInput.focus();
            return;
        }

        if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showToast("Please enter a valid email address.", "error");
            emailInput.focus();
            return;
        }

        saveProfileBtn.disabled = true;
        saveProfileText.innerHTML = '<span class="btn-spinner"></span> Saving...';

        const fd = new FormData();
        fd.append("full_name", name);
        fd.append("mobile_number", mobile);
        fd.append("email_address", email);

        fetch(BASE_URL + "ajax/update-profile.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                saveProfileBtn.disabled = false;
                saveProfileText.innerHTML = '<i class="bi bi-check-lg"></i> Save Profile';

                if (res && res.success) {
                    showToast(res.message || "Profile updated successfully.");
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    showToast((res && res.message) || "Failed to update profile.", "error");
                }
            })
            .catch(() => {
                saveProfileBtn.disabled = false;
                saveProfileText.innerHTML = '<i class="bi bi-check-lg"></i> Save Profile';
                showToast("Unable to connect to server.", "error");
            });
    });

    /* ---------- CHANGE PASSWORD ---------- */
    passwordForm.addEventListener("submit", function (e) {
        e.preventDefault();

        const current = (currentPwd.value || "").trim();
        const newPass = (newPwd.value || "").trim();
        const confirm = (confirmPwd.value || "").trim();

        if (!current) {
            showToast("Please enter your current password.", "error");
            currentPwd.focus();
            return;
        }

        if (!newPass) {
            showToast("Please enter a new password.", "error");
            newPwd.focus();
            return;
        }

        if (newPass.length < 6) {
            showToast("New password must be at least 6 characters.", "error");
            newPwd.focus();
            return;
        }

        if (newPass === current) {
            showToast("New password must be different from current.", "error");
            newPwd.focus();
            return;
        }

        if (!confirm) {
            showToast("Please confirm your new password.", "error");
            confirmPwd.focus();
            return;
        }

        if (newPass !== confirm) {
            showToast("Passwords do not match.", "error");
            confirmPwd.focus();
            confirmPwd.select();
            return;
        }

        savePasswordBtn.disabled = true;
        savePasswordText.innerHTML = '<span class="btn-spinner"></span> Updating...';

        const fd = new FormData();
        fd.append("current_password", current);
        fd.append("new_password", newPass);
        fd.append("confirm_password", confirm);

        fetch(BASE_URL + "ajax/update-password.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin"
        })
            .then(r => r.json().catch(() => null))
            .then(res => {
                savePasswordBtn.disabled = false;
                savePasswordText.innerHTML = '<i class="bi bi-shield-check"></i> Update Password';

                if (res && res.success) {
                    showToast(res.message || "Password updated successfully.");
                    passwordForm.reset();
                } else {
                    showToast((res && res.message) || "Failed to update password.", "error");
                }
            })
            .catch(() => {
                savePasswordBtn.disabled = false;
                savePasswordText.innerHTML = '<i class="bi bi-shield-check"></i> Update Password';
                showToast("Unable to connect to server.", "error");
            });
    });

})();