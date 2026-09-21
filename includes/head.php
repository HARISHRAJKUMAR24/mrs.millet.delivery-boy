<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Mrs Mill@ - Delivery-boy</title>
<!-- Favicon -->
<?php
$faviconImage = getData('favicon_image', 'settings', 'id = 1');

$faviconUrl = $faviconImage
    ? ADMIN_URL . $faviconImage
    : '';
?>
<link
    rel="icon"
    type="image/png"
    href="<?= htmlspecialchars($faviconUrl) ?>">
<!-- Bootstrap -->
<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet">

<!-- Bootstrap Icons -->
<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
    rel="stylesheet">

<!-- Google Fonts -->
<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap"
    rel="stylesheet">

<link
    href="<?= BASE_URL ?>assets/css/style.css"
    rel="stylesheet">