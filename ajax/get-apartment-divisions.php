<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    jsonResponse(false, 'Invalid apartment ID.');
}

try {
    $stmt = $pdo->prepare(
        "SELECT divisions FROM apartments WHERE id = ? AND status = 1 LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(false, 'Apartment not found.');
    }

    $divs = json_decode($row['divisions'] ?? '[]', true);
    if (!is_array($divs)) $divs = [];

    $out = [];
    foreach ($divs as $d) {
        $out[] = [
            'division' => (string)($d['division'] ?? ''),
            'charge'   => (float)($d['charge'] ?? 0)
        ];
    }

    jsonResponse(true, 'OK', $out);

} catch (PDOException $e) {
    jsonResponse(false, 'Failed to load divisions.');
}