<?php
/* Returns all active products with their variants (including container fields). */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

try {
    $stmt = $pdo->query(
        "SELECT id, product_code, product_name, product_image
         FROM products
         WHERE status = 1
         ORDER BY product_name ASC"
    );
    $products = $stmt->fetchAll();

    $out = [];

    foreach ($products as $p) {

        $vStmt = $pdo->prepare(
            "SELECT id, quantity, quantity_unit, quantity_name, price,
                    container_enabled, container_price
             FROM product_variants
             WHERE product_code = ? AND status = 1
             ORDER BY quantity ASC"
        );
        $vStmt->execute([$p['product_code']]);
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
    jsonResponse(false, 'Failed to load products.');
}