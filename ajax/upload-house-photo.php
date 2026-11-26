<?php
/* =========================================================
   MRS MILL@ — DELIVERY BOY: UPLOAD / DELETE HOUSE PHOTO
   File: ./ajax/upload-house-photo.php

   Same image logic as customer save:
   - Admin folder = DOCUMENT_ROOT + path of ADMIN_URL
   - Saved to: uploads/houses/Y/m/d/customer_<id>/house.<ext>
   - Link returned with ADMIN_URL
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/function.php';

header('Content-Type: application/json; charset=utf-8');

/* ---------------- AUTH ---------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed.');
}
if (!isset($_SESSION['delivery_boy_id']) || (int)$_SESSION['delivery_boy_id'] <= 0) {
    jsonResponse(false, 'Not logged in.');
}

$boyId  = (int)$_SESSION['delivery_boy_id'];
$addr   = trim($_POST['address'] ?? '');
$mobile = trim($_POST['customer_mobile'] ?? '');
$name   = trim($_POST['customer_name'] ?? '');
$action = trim($_POST['action'] ?? 'upload');   // upload | delete

if ($addr === '' && $mobile === '') {
    jsonResponse(false, 'Missing address or mobile.');
}

/* =========================================================
   ADMIN ROOT — SAME AS CUSTOMER SAVE
   DOCUMENT_ROOT + path of ADMIN_URL
   ========================================================= */
function adminAppRoot(): string
{
    if (defined('ADMIN_URL')) {
        $u = parse_url(ADMIN_URL);
        if (!empty($u['path'])) {
            $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
            if ($docroot !== '') {
                return $docroot . rtrim($u['path'], '/');
            }
        }
    }
    return '';
}

function resolveAdminFile(string $adminRoot, ?string $relative): ?string
{
    if (!$relative) return null;
    $relative = explode('?', $relative)[0];
    $abs = $adminRoot . '/' . ltrim($relative, '/');
    return is_file($abs) ? $abs : null;
}

function deleteFileAndCleanFolders(string $adminRoot, ?string $relative): void
{
    if (!$relative) return;
    $relative = explode('?', $relative)[0];

    $abs = $adminRoot . '/' . ltrim($relative, '/');
    if (is_file($abs)) @unlink($abs);

    $dir    = dirname($abs);
    $stopAt = rtrim($adminRoot . '/uploads/houses', '/');

    if (strpos($dir, $stopAt) !== 0) return;

    while (strlen($dir) >= strlen($stopAt) && is_dir($dir)) {
        $items = @scandir($dir);
        if ($items === false) break;

        $real = array_values(array_diff($items, ['.', '..']));
        if (count($real) > 0) break;

        @rmdir($dir);
        $dir = dirname($dir);

        if ($dir === $stopAt || $dir === dirname($stopAt)) break;
    }
}

/* =========================================================
   FIND THE ORDER
   ========================================================= */
function findOrderForDeliveryBoy(PDO $pdo, int $boyId, string $addr, string $mobile): ?array
{
    if ($mobile !== '') {
        try {
            $stmt = $pdo->prepare(
                "SELECT o.id, o.address_type, o.address_json
                 FROM orders o
                 LEFT JOIN customers c ON c.customer_id = o.customer_id
                 WHERE o.delivery_boy_id = ?
                   AND (
                        c.mobile_number = ?
                     OR JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.customer_mobile')) = ?
                   )
                 ORDER BY o.id DESC
                 LIMIT 1"
            );
            $stmt->execute([$boyId, $mobile, $mobile]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (PDOException $e) {
        }
    }

    if ($addr !== '') {
        try {
            $stmt = $pdo->prepare(
                "SELECT o.id, o.address_type, o.address_json
                 FROM orders o
                 WHERE JSON_VALID(o.address_json)
                   AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.full_address')) = ?
                   AND o.delivery_boy_id = ?
                 ORDER BY o.id DESC
                 LIMIT 1"
            );
            $stmt->execute([$addr, $boyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (PDOException $e) {
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT o.id, o.address_type, o.address_json
                 FROM orders o
                 WHERE JSON_VALID(o.address_json)
                   AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.full_address')))) = LOWER(TRIM(?))
                   AND o.delivery_boy_id = ?
                 ORDER BY o.id DESC
                 LIMIT 1"
            );
            $stmt->execute([$addr, $boyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (PDOException $e) {
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT o.id, o.address_type, o.address_json
                 FROM orders o
                 WHERE JSON_VALID(o.address_json)
                   AND JSON_UNQUOTE(JSON_EXTRACT(o.address_json, '$.full_address')) LIKE ?
                   AND o.delivery_boy_id = ?
                 ORDER BY o.id DESC
                 LIMIT 1"
            );
            $stmt->execute(['%' . $addr . '%', $boyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (PDOException $e) {
        }
    }

    return null;
}

/* =========================================================
   ADMIN FOLDER CHECK
   ========================================================= */
$adminRoot = adminAppRoot();
if ($adminRoot === '' || !is_dir($adminRoot)) {
    jsonResponse(false, 'Admin folder not found: [' . $adminRoot . ']');
}

/* =========================================================
   MATCH THE ORDER
   ========================================================= */
$matchedOrder = findOrderForDeliveryBoy($pdo, $boyId, $addr, $mobile);
if (!$matchedOrder) {
    jsonResponse(false, 'No order found for this customer. Please refresh and try again.');
}

$orderAddr = json_decode($matchedOrder['address_json'] ?? '[]', true);
if (!is_array($orderAddr)) $orderAddr = [];

$orderAddressType = strtolower((string)($matchedOrder['address_type'] ?? 'apartment'));
if (!in_array($orderAddressType, ['apartment', 'individual_house'], true)) {
    $orderAddressType = 'apartment';
}

$orderAptCode     = (string)($orderAddr['apartment_code'] ?? '');
$orderAptId       = (int)($orderAddr['apartment_id'] ?? 0);
$orderAptName     = (string)($orderAddr['apartment_name'] ?? '');
$orderFullAddress = (string)($orderAddr['full_address'] ?? '');

if ($mobile === '') {
    $mobile = (string)($orderAddr['customer_mobile'] ?? '');
    if ($mobile === '') {
        try {
            $cs = $pdo->prepare(
                "SELECT c.mobile_number
                 FROM orders o
                 LEFT JOIN customers c ON c.customer_id = o.customer_id
                 WHERE o.id = ? LIMIT 1"
            );
            $cs->execute([(int)$matchedOrder['id']]);
            $mobile = (string)$cs->fetchColumn();
        } catch (PDOException $e) {
        }
    }
}

if ($addr === '') {
    $addr = $orderFullAddress;
}

/* =========================================================
   FIND OR CREATE THE REQUEST ROW
   ========================================================= */
$requestId = 0;
$oldImage  = null;

try {
    if ($orderAddressType === 'apartment') {

        if ($orderAptId > 0 || $orderAptCode !== '' || $orderAptName !== '') {

            $sql = "SELECT id, house_image
                    FROM customer_address_requests
                    WHERE request_type = 'apartment'
                      AND (
                            created_apartment_id = ?
                         OR requested_apartment = ?
                         OR requested_apartment = ?
                      )";
            $params = [$orderAptId, $orderAptCode, $orderAptName];

            if ($mobile !== '') {
                $sql .= " AND customer_mobile = ?";
                $params[] = $mobile;
            }

            $sql .= " ORDER BY id DESC LIMIT 1";

            $rs = $pdo->prepare($sql);
            $rs->execute($params);
            $row = $rs->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $requestId = (int)$row['id'];
                $oldImage  = $row['house_image'];
            }
        }

        if ($requestId <= 0) {
            $ins = $pdo->prepare(
                "INSERT INTO customer_address_requests
                    (customer_name, customer_mobile,
                     requested_apartment, requested_division,
                     request_type, status,
                     created_apartment_id,
                     created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'apartment', 0, ?, NOW(), NOW())"
            );
            $ins->execute([
                $name,
                $mobile,
                $orderAptName !== '' ? $orderAptName : $orderAptCode,
                (string)($orderAddr['division_label'] ?? ''),
                $orderAptId > 0 ? $orderAptId : null,
            ]);
            $requestId = (int)$pdo->lastInsertId();
        }
    } else {

        if ($mobile !== '') {
            $rs = $pdo->prepare(
                "SELECT id, house_image
                 FROM customer_address_requests
                 WHERE request_type = 'individual_house'
                   AND requested_apartment = ?
                   AND customer_mobile = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $rs->execute([$addr, $mobile]);
        } else {
            $rs = $pdo->prepare(
                "SELECT id, house_image
                 FROM customer_address_requests
                 WHERE request_type = 'individual_house'
                   AND requested_apartment = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $rs->execute([$addr]);
        }
        $row = $rs->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $requestId = (int)$row['id'];
            $oldImage  = $row['house_image'];
        }

        if ($requestId <= 0) {
            $ins = $pdo->prepare(
                "INSERT INTO customer_address_requests
                    (customer_name, customer_mobile, requested_apartment,
                     request_type, status, created_at, updated_at)
                 VALUES (?, ?, ?, 'individual_house', 0, NOW(), NOW())"
            );
            $ins->execute([$name, $mobile, $addr]);
            $requestId = (int)$pdo->lastInsertId();
        }
    }
} catch (PDOException $e) {
    jsonResponse(false, 'DB error: ' . $e->getMessage());
}

if ($requestId <= 0) {
    jsonResponse(false, 'Could not find or create a request row.');
}

/* =========================================================
   DELETE
   ========================================================= */
if ($action === 'delete') {
    deleteFileAndCleanFolders($adminRoot, $oldImage);

    try {
        $upd = $pdo->prepare(
            "UPDATE customer_address_requests
             SET house_image = NULL, updated_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([$requestId]);
    } catch (PDOException $e) {
        jsonResponse(false, 'DB error: ' . $e->getMessage());
    }

    jsonResponse(true, 'Photo removed.', ['request_id' => $requestId]);
}

/* =========================================================
   UPLOAD
   ========================================================= */
if (empty($_FILES['house_image']) || $_FILES['house_image']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, 'No image uploaded.');
}

$file = $_FILES['house_image'];
if ($file['size'] > 5 * 1024 * 1024) {
    jsonResponse(false, 'Image too large (max 5 MB).');
}

$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mime    = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    jsonResponse(false, 'Only JPG, PNG or WEBP allowed.');
}
$ext = $allowed[$mime];

$subdir = 'uploads/houses/' . date('Y') . '/' . date('m') . '/' . date('d')
    . '/customer_' . $requestId . '/';
$absDir   = $adminRoot . '/' . $subdir;
$fileName = 'house.' . $ext;
$absFile  = $absDir . $fileName;

if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
    jsonResponse(false, 'Could not create upload folder: ' . $absDir);
}

if (!move_uploaded_file($file['tmp_name'], $absFile)) {
    jsonResponse(false, 'Failed to save file.');
}
@chmod($absFile, 0644);

/* Remove old file */
if (!empty($oldImage)) {
    $oldAbs = resolveAdminFile($adminRoot, $oldImage);
    if ($oldAbs && $oldAbs !== $absFile) {
        deleteFileAndCleanFolders($adminRoot, $oldImage);
    }
}

/* Remove same-folder variants */
foreach (glob($absDir . 'house.*') ?: [] as $f) {
    if ($f !== $absFile && is_file($f)) @unlink($f);
}

$relative = $subdir . $fileName;

/* =========================================================
   UPDATE THE DB
   ========================================================= */
try {
    $upd = $pdo->prepare(
        "UPDATE customer_address_requests
         SET house_image = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $upd->execute([$relative, $requestId]);

    if ($upd->rowCount() === 0) {
        $chk = $pdo->prepare(
            "SELECT house_image FROM customer_address_requests WHERE id = ? LIMIT 1"
        );
        $chk->execute([$requestId]);
        $saved = $chk->fetchColumn();

        if ($saved !== $relative) {
            @unlink($absFile);
            jsonResponse(false, 'Database update did not persist. Please try again.');
        }
    }
} catch (PDOException $e) {
    @unlink($absFile);
    jsonResponse(false, 'DB error: ' . $e->getMessage());
}

/* ---------------- SUCCESS ---------------- */
$verify = $pdo->prepare(
    "SELECT house_image FROM customer_address_requests WHERE id = ? LIMIT 1"
);
$verify->execute([$requestId]);
$dbPath = (string)$verify->fetchColumn();

jsonResponse(true, 'Photo saved.', [
    'request_id'      => $requestId,
    'request_type'    => $orderAddressType,
    'house_image'     => $dbPath,
    'house_image_url' => rtrim(ADMIN_URL, '/') . '/' . ltrim($dbPath, '/') . '?v=' . time(),
]);