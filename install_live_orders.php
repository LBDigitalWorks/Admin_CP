<?php
require_once __DIR__ . '/config.php';

function out($msg, $ok=true) {
    echo '<p style="margin:6px 0;color:' . ($ok?'#0a0':'#b00') . '">'
       . htmlspecialchars($msg) . '</p>';
}

try {
    // 1) Create drivers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drivers (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(120) NOT NULL,
          phone_e164 VARCHAR(20) NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    out("drivers table OK");

    // 2) Create driver_areas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_areas (
          id INT AUTO_INCREMENT PRIMARY KEY,
          driver_id INT NOT NULL,
          area_prefix VARCHAR(10) NOT NULL,
          UNIQUE KEY uniq_driver_area (driver_id, area_prefix),
          CONSTRAINT fk_da_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    out("driver_areas table OK");

    // 3) Ensure orders table is InnoDB (FKs require it)
    try { $pdo->exec("ALTER TABLE orders ENGINE=InnoDB"); } catch (Throwable $e) {}

    // Helpers
    $colExists = function($table, $col) use ($pdo) {
        $q = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $q->execute([$table, $col]);
        return (bool)$q->fetchColumn();
    };

    // 4) Add columns to orders if missing
    $ordersCols = [
        'address_line1'      => "ALTER TABLE orders ADD COLUMN address_line1 VARCHAR(255) NULL",
        'address_postcode'   => "ALTER TABLE orders ADD COLUMN address_postcode VARCHAR(20) NULL",
        'assigned_driver_id' => "ALTER TABLE orders ADD COLUMN assigned_driver_id INT NULL",
        'delivery_status'    => "ALTER TABLE orders ADD COLUMN delivery_status ENUM('new','assigned','enroute','delivered','failed') DEFAULT 'new'",
        'sent_whatsapp_at'   => "ALTER TABLE orders ADD COLUMN sent_whatsapp_at DATETIME NULL",
    ];
    foreach ($ordersCols as $col => $sql) {
        if (!$colExists('orders', $col)) {
            $pdo->exec($sql);
            out("orders.$col added");
        } else {
            out("orders.$col already exists");
        }
    }

    // 5) Add FK if not present
    $fkExists = function($table, $fkName) use ($pdo) {
        $q = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ");
        $q->execute([$table, $fkName]);
        return (bool)$q->fetchColumn();
    };
    if (!$fkExists('orders', 'fk_orders_driver')) {
        try {
            $pdo->exec("
                ALTER TABLE orders
                ADD CONSTRAINT fk_orders_driver
                FOREIGN KEY (assigned_driver_id) REFERENCES drivers(id) ON DELETE SET NULL
            ");
            out("Foreign key fk_orders_driver added");
        } catch (Throwable $e) {
            out("FK add failed (will continue): " . $e->getMessage(), false);
        }
    } else {
        out("Foreign key fk_orders_driver already exists");
    }

    // 6) Final verification
    $check = $pdo->query("SHOW TABLES LIKE 'drivers'")->fetchColumn();
    if (!$check) { out("drivers table still missing!", false); }
    $check2 = $pdo->query("SHOW TABLES LIKE 'driver_areas'")->fetchColumn();
    if (!$check2) { out("driver_areas table still missing!", false); }

    out("Installer finished ✅");
} catch (Throwable $e) {
    out("Fatal installer error: " . $e->getMessage(), false);
}
