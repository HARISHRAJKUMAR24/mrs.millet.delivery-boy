<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.');
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId    = (int)$_SESSION['delivery_boy_id'];
$mobile   = trim($_POST['customer_mobile'] ?? '');
$received = (int)($_POST['received_containers'] ?? 0);
$note     = trim($_POST['note'] ?? '');

if ($mobile === '') jsonResponse(false, 'Missing customer mobile.');
if ($received <= 0) jsonResponse(false, 'Enter a valid count.');

try {
    // Find customer ID
    $cs = $pdo->prepare("SELECT id, full_name FROM customers WHERE mobile_number = ? LIMIT 1");
    $cs->execute([$mobile]);
    $cust = $cs->fetch(PDO::FETCH_ASSOC);

    if (!$cust) jsonResponse(false, 'Customer not found.');

    // Insert request
    $ins = $pdo->prepare(
        "INSERT INTO delivery_boy_requests 
            (delivery_boy_id, customer_id, customer_name, customer_mobile, request_type, container_count, note, status) 
         VALUES (?, ?, ?, ?, 'container_return', ?, ?, 'pending')"
    );
    $ins->execute([
        $boyId,
        $cust['id'],
        $cust['full_name'],
        $mobile,
        $received,
        $note !== '' ? $note : null
    ]);

    jsonResponse(true, 'Request sent to admin for approval.');

} catch (PDOException $e) {
    jsonResponse(false, 'Server error: ' . $e->getMessage());
}