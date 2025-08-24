<?php
require_once __DIR__ . '/whatsapp.php';

function extract_outward(string $postcode): string {
    // Normalize: remove extra spaces, uppercase
    $pc = strtoupper(trim($postcode));
    // outward is everything before the last space (simple UK heuristic)
    // e.g. "S1 2AB" -> "S1", "S35 9ZZ" -> "S35"
    if (strpos($pc, ' ') !== false) {
        return substr($pc, 0, strpos($pc, ' '));
    }
    // if no space (fallback), strip trailing digits to get area-ish "S1" from "S12AB"
    return preg_replace('/\d.*$/', '', $pc);
}

function find_driver_for_postcode(PDO $pdo, string $postcode): ?array {
    $outward = extract_outward($postcode);
    $stmt = $pdo->prepare("
        SELECT d.*
        FROM driver_areas da
        JOIN drivers d ON d.id = da.driver_id AND d.active = 1
        WHERE da.area_prefix = ?
        ORDER BY d.id ASC
        LIMIT 1
    ");
    $stmt->execute([$outward]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
    return $driver ?: null;
}

function send_order_to_driver(PDO $pdo, int $orderId, int $driverId): bool {
    // Load order + driver
    $stmt = $pdo->prepare("
        SELECT o.*, d.name AS driver_name, d.phone_e164
        FROM orders o
        JOIN drivers d ON d.id = ?
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$driverId, $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    $msg = "New delivery:\n"
         . "Order #{$row['id']}\n"
         . "Total: £" . number_format((float)$row['total'], 2) . "\n"
         . "Address: {$row['address_line1']}\n"
         . "Postcode: {$row['address_postcode']}\n"
         . "Notes: " . ($row['notes'] ?? '-') . "\n"
         . "Please confirm.";

    $ok = send_whatsapp($row['phone_e164'], $msg);

    // mark assignment and sent time if success
    if ($ok) {
        $upd = $pdo->prepare("
            UPDATE orders
            SET assigned_driver_id = ?, delivery_status = 'assigned', sent_whatsapp_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$driverId, $orderId]);
    }
    return $ok;
}
