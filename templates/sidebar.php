<?php 
// Get the current page file name (e.g., 'index.php', 'history.php')
$currentPage = basename($_SERVER['PHP_SELF']); 
?>
<aside class="sidebar" id="sidebar">

    <!-- Close (X) button -->
    <button class="sb-close" id="sidebarClose" aria-label="Close menu">
        <i class="bi bi-x-lg"></i>
    </button>

    <!-- Brand — BIG logo -->
    <div class="sb-brand">
        <div class="sb-logo">
            <?php if (!empty($logoUrl)): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
            <?php else: ?>
                <span class="fallback">M</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Nav -->
    <ul class="sb-nav">
        <div class="sb-nav-title">Main</div>

        <li>
            <a href="<?= BASE_URL ?>index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i>
                Dashboard
            </a>
        </li>

        <li>
            <!-- Highlights for the main delivery list AND the order detail pages -->
            <a href="<?= BASE_URL ?>delivery-orders.php" class="<?= in_array($currentPage, ['delivery-orders.php', 'apartment-orders.php', 'individual-house-orders.php']) ? 'active' : '' ?>">
                <i class="bi bi-bag-check"></i>
                My Delivery
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>history.php" class="<?= $currentPage === 'history.php' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i>
                History
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>customer-payment-wallet.php" class="<?= $currentPage === 'customer-payment-wallet.php' ? 'active' : '' ?>">
                <i class="bi bi-wallet2"></i>
                Customer Wallet
            </a>
        </li>

        <div class="sb-nav-title">Account</div>

        <li>
            <a href="<?= BASE_URL ?>settings.php" class="<?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                <i class="bi bi-person-circle"></i>
                My Profile
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>notifications.php" class="<?= $currentPage === 'notifications.php' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i>
                Notifications
            </a>
        </li>
        <li>
            <a href="<?= BASE_URL ?>help.php" class="<?= $currentPage === 'help.php' ? 'active' : '' ?>">
                <i class="bi bi-question-circle"></i>
                Help & Support
            </a>
        </li>
    </ul>

    <!-- Logout -->
    <div class="sb-logout">
        <a href="<?= BASE_URL ?>logout.php">
            <i class="bi bi-box-arrow-right"></i>
            Logout
        </a>
    </div>
</aside>

