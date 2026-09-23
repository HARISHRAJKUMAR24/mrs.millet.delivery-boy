<?php
/* Returns products from the currently active menu (with container fields). */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

try {
    /* Find active menu */
    $menuStmt = $pdo->prepare(
        "SELECT menu_code FROM menus
         WHERE status = 1
           AND NOW() BETWEEN start_at AND end_at
         ORDER BY id DESC
         LIMIT 1"
    );
    $menuStmt->execute();
    $menu = $menuStmt->fetch();

    if (!$menu) {
        jsonResponse(true, 'OK', []);
    }

    $menuCode = $menu['menu_code'];

    /* Get distinct products in the menu */
    $stmt = $pdo->prepare(
        "SELECT DISTINCT p.id, p.product_code, p.product_name, p.product_image
         FROM menu_products mp
         INNER JOIN products p ON p.product_code = mp.product_code
         WHERE mp.menu_code = ? AND p.status = 1
         ORDER BY p.product_name ASC"
    );
    $stmt->execute([$menuCode]);
    $products = $stmt->fetchAll();

    $out = [];

    foreach ($products as $p) {

        $vStmt = $pdo->prepare(
            "SELECT v.id, v.quantity, v.quantity_unit, v.quantity_name, v.price,
                    v.container_enabled, v.container_price
             FROM menu_products mp
             INNER JOIN product_variants v ON v.id = mp.variant_id
             WHERE mp.menu_code = ? AND mp.product_code = ? AND v.status = 1
             ORDER BY v.quantity ASC"
        );
        $vStmt->execute([$menuCode, $p['product_code']]);
        $variants = $vStmt->fetchAll();

        $variantList = [];
        foreach ($variants as $v) {
            $variantList[] = [
                'id'                => (int)$v['id'],
                'quantity'          => (float)$v['quantity'],
                'quantity_unit'     => $v['quantity_unit'],
                'quantity_name'     => $v['quantity_name'],
                'price'             => (float)$v['price'],
                'container_enabled' => (int)($v['container_enabled'] ?? 0),
                'container_price'   => (float)($v['container_price'] ?? 0)
            ];
        }

        $img = !empty($p['product_image']) ? ADMIN_URL . $p['product_image'] : '';
        $minPrice = 0;
        if (!empty($variantList)) {
            $minPrice = min(array_map(fn($v) => $v['price'], $variantList));
        }

        $out[] = [
            'id'       => (int)$p['id'],
            'code'     => $p['product_code'],
            'name'     => $p['product_name'],
            'image'    => $img,
            'price'    => $minPrice,
            'variants' => $variantList
        ];
    }

    jsonResponse(true, 'OK', $out);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load menu products.');
}