<?php
require_once __DIR__ . '/config.php';

function out($m,$ok=true){echo '<p style="margin:6px 0;color:'.($ok?'#0a0':'#b00').'">'.htmlspecialchars($m).'</p>';}

try {
  // Categories
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS menu_categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      description TEXT NULL,
      image_path VARCHAR(255) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  out('menu_categories OK');

  // Items
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS menu_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      category_id INT NOT NULL,
      name VARCHAR(160) NOT NULL,
      description TEXT NULL,
      image_path VARCHAR(255) NULL,
      base_price DECIMAL(10,2) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  out('menu_items OK');

  // Sizes (optional per item)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS menu_item_sizes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      item_id INT NOT NULL,
      size_label VARCHAR(60) NOT NULL,
      price DECIMAL(10,2) NOT NULL,
      CONSTRAINT fk_size_item FOREIGN KEY (item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
      UNIQUE KEY uniq_item_size (item_id, size_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  out('menu_item_sizes OK');

  out('Installer finished ✅');
} catch (Throwable $e) {
  out('Installer error: '.$e->getMessage(), false);
}
