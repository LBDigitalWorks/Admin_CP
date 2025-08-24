<?php
// Start session safely
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/config.php';

if (empty($_SESSION['user'])) { header("Location: index.php"); exit; }

$pageTitle = "Menu Manager";
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$u = function(string $path = '') use ($baseUrl): string {
  $path = ltrim($path, '/'); return $baseUrl ? ($baseUrl . '/' . $path) : $path;
};

// ---- Guard: ensure tables exist ----
function tableExists(PDO $pdo, string $t): bool {
  try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch(Throwable $e){ return false; }
}
if (!tableExists($pdo,'menu_categories') || !tableExists($pdo,'menu_items') || !tableExists($pdo,'menu_item_sizes')) {
  header("Location: " . $u('install_menu.php?from=menu')); exit;
}

// ---- Upload helpers ----
function ensureDir(string $dir): void { if (!is_dir($dir)) @mkdir($dir, 0775, true); }
function handle_image_upload(string $field, string $subdir): ?string {
  if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
  $f = $_FILES[$field];
  if ($f['error'] !== UPLOAD_ERR_OK) return null;
  if ($f['size'] > 3 * 1024 * 1024) return null;

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $f['tmp_name']); finfo_close($finfo);
  $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
  if (!$ext) return null;

  $destRoot = __DIR__ . '/uploads/' . $subdir; ensureDir($destRoot);
  $name = $subdir . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $destFs = $destRoot . '/' . $name;

  if (!move_uploaded_file($f['tmp_name'], $destFs)) return null;
  return 'uploads/' . $subdir . '/' . $name; // relative web path
}

// ---- Actions ----

// Add category
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'add_category') {
  $name = trim($_POST['cat_name'] ?? '');
  $desc = trim($_POST['cat_description'] ?? '');
  $sort = (int)($_POST['cat_sort'] ?? 0);
  $active = (int)($_POST['cat_active'] ?? 1);
  $img = handle_image_upload('cat_image', 'menu_categories');
  if ($name !== '') {
    $stmt = $pdo->prepare("INSERT INTO menu_categories (name, description, image_path, sort_order, active) VALUES (?,?,?,?,?)");
    $stmt->execute([$name, $desc, $img, $sort, $active?1:0]);
  }
  header("Location: " . $u('menu.php')); exit;
}

// Save category sort (bulk)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'save_cat_sort') {
  if (!empty($_POST['sort']) && is_array($_POST['sort'])) {
    $stmt = $pdo->prepare("UPDATE menu_categories SET sort_order=? WHERE id=?");
    foreach ($_POST['sort'] as $id => $val) { $stmt->execute([(int)$val, (int)$id]); }
  }
  header("Location: " . $u('menu.php')); exit;
}

// Toggle / Delete category
if (isset($_GET['toggle_cat'])) {
  $id=(int)$_GET['toggle_cat'];
  $pdo->prepare("UPDATE menu_categories SET active=1-active WHERE id=?")->execute([$id]);
  header("Location: " . $u('menu.php')); exit;
}
if (isset($_GET['delete_cat'])) {
  $id=(int)$_GET['delete_cat'];
  // delete category image if any
  $img = $pdo->prepare("SELECT image_path FROM menu_categories WHERE id=?"); $img->execute([$id]);
  $p = $img->fetchColumn(); if ($p) { $fs = __DIR__ . '/' . ltrim($p,'/'); if (is_file($fs)) @unlink($fs); }
  $pdo->prepare("DELETE FROM menu_categories WHERE id=?")->execute([$id]);
  header("Location: " . $u('menu.php')); exit;
}

// Set selected category (right pane shows items for this)
$selectedCatId = isset($_GET['cat']) ? max(0,(int)$_GET['cat']) : 0;

// Add item
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'add_item') {
  $catId = (int)($_POST['item_cat_id'] ?? 0);
  $name = trim($_POST['item_name'] ?? '');
  $desc = trim($_POST['item_description'] ?? '');
  $sort = (int)($_POST['item_sort'] ?? 0);
  $active = (int)($_POST['item_active'] ?? 1);
  $basePrice = ($_POST['item_base_price'] === '' ? null : (float)$_POST['item_base_price']);
  $img = handle_image_upload('item_image', 'menu_items');
  if ($catId && $name !== '') {
    $stmt = $pdo->prepare("INSERT INTO menu_items (category_id, name, description, image_path, base_price, sort_order, active)
                           VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$catId, $name, $desc, $img, $basePrice, $sort, $active?1:0]);
    $selectedCatId = $catId; // keep focus
  }
  header("Location: " . $u('menu.php?cat='.$selectedCatId)); exit;
}

// Toggle / Delete item
if (isset($_GET['toggle_item'])) {
  $id=(int)$_GET['toggle_item'];
  $pdo->prepare("UPDATE menu_items SET active=1-active WHERE id=?")->execute([$id]);
  // keep same cat
  $ci = $pdo->prepare("SELECT category_id FROM menu_items WHERE id=?"); $ci->execute([$id]); $selectedCatId = (int)$ci->fetchColumn();
  header("Location: " . $u('menu.php?cat='.$selectedCatId)); exit;
}
if (isset($_GET['delete_item'])) {
  $id=(int)$_GET['delete_item'];
  $ci = $pdo->prepare("SELECT category_id, image_path FROM menu_items WHERE id=?"); $ci->execute([$id]); $row = $ci->fetch(PDO::FETCH_ASSOC);
  if ($row && !empty($row['image_path'])) { $fs = __DIR__ . '/' . ltrim($row['image_path'],'/'); if (is_file($fs)) @unlink($fs); }
  $pdo->prepare("DELETE FROM menu_items WHERE id=?")->execute([$id]);
  $selectedCatId = (int)($row['category_id'] ?? 0);
  header("Location: " . $u('menu.php?cat='.$selectedCatId)); exit;
}

// Save item sort (bulk)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'save_item_sort') {
  if (!empty($_POST['sort']) && is_array($_POST['sort'])) {
    $stmt = $pdo->prepare("UPDATE menu_items SET sort_order=? WHERE id=?");
    foreach ($_POST['sort'] as $id => $val) { $stmt->execute([(int)$val, (int)$id]); }
  }
  $selectedCatId = (int)($_POST['current_cat'] ?? 0);
  header("Location: " . $u('menu.php?cat='.$selectedCatId)); exit;
}

// Add size to item
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'add_size') {
  $itemId = (int)($_POST['size_item_id'] ?? 0);
  $label  = trim($_POST['size_label'] ?? '');
  $price  = (float)($_POST['size_price'] ?? 0);
  if ($itemId && $label !== '') {
    // find item's category for redirect
    $ci = $pdo->prepare("SELECT category_id FROM menu_items WHERE id=?"); $ci->execute([$itemId]); $selectedCatId = (int)$ci->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO menu_item_sizes (item_id, size_label, price) VALUES (?,?,?)");
    try { $stmt->execute([$itemId, $label, $price]); } catch (Throwable $e) { /* ignore dup size */ }
  }
  header("Location: " . $u('menu.php?cat='.$selectedCatId)); exit;
}

// Delete size
if (isset($_GET['delete_size'])) {
  $sid = (int)$_GET['delete_size'];
  // find item/category
  $q = $pdo->prepare("SELECT mis.item_id, mi.category_id FROM menu_item_sizes mis JOIN menu_items mi ON mi.id=mis.item_id WHERE mis.id=?");
  $q->execute([$sid]); $r = $q->fetch(PDO::FETCH_ASSOC);
  if ($r) { $pdo->prepare("DELETE FROM menu_item_sizes WHERE id=?")->execute([$sid]); $selectedCatId = (int)$r['category_id']; }
  header("Location: " . $u('menu.php?cat='.$selectedCatId)); exit;
}

// ---- Load data ----
$categories = $pdo->query("SELECT * FROM menu_categories ORDER BY active DESC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

if (!$selectedCatId && $categories) { $selectedCatId = (int)$categories[0]['id']; }

$items = [];
if ($selectedCatId) {
  $st = $pdo->prepare("SELECT * FROM menu_items WHERE category_id=? ORDER BY active DESC, sort_order ASC, name ASC");
  $st->execute([$selectedCatId]); $items = $st->fetchAll(PDO::FETCH_ASSOC);
}
// sizes grouped by item
$sizesByItem = [];
if ($items) {
  $ids = array_column($items, 'id');
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ss = $pdo->prepare("SELECT * FROM menu_item_sizes WHERE item_id IN ($in) ORDER BY price ASC");
    $ss->execute($ids);
    while ($r = $ss->fetch(PDO::FETCH_ASSOC)) { $sizesByItem[$r['item_id']][] = $r; }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-50 via-white to-gray-100 text-gray-900 min-h-screen">

<!-- Header -->
<header class="bg-gray-900 text-white">
  <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
    <a class="flex items-center gap-3" href="<?= htmlspecialchars($u('index.php')) ?>">
      <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 grid place-items-center shadow-lg">LB</div>
      <div class="font-semibold tracking-wide">Admin Panel</div>
    </a>
    <nav class="hidden md:flex items-center gap-2">
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('index.php')) ?>">Dashboard</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('transactions.php')) ?>">Transactions</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('customers.php')) ?>">Customers</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('orders.php')) ?>">Orders</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('reports.php')) ?>">Reports</a>
      <a class="px-3 py-2 rounded-lg hover:bg-gray-800" href="<?= htmlspecialchars($u('settings.php')) ?>">Settings</a>
      <a class="px-3 py-2 rounded-lg bg-gray-800 text-white" href="<?= htmlspecialchars($u('menu.php')) ?>">Menu</a>
    </nav>
    <div class="flex items-center gap-3">
      <span class="hidden sm:inline text-sm text-gray-300">Hi, <?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?></span>
      <a href="<?= htmlspecialchars($u('index.php?logout=1')) ?>" class="px-3 py-2 rounded-lg bg-red-500 hover:bg-red-600 text-white">Logout</a>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-8 space-y-8">

  <!-- Grid: Categories (left) / Items (right) -->
  <div class="grid lg:grid-cols-2 gap-6">

    <!-- Categories panel -->
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <h2 class="text-lg font-semibold mb-4">Categories</h2>

      <!-- Add Category -->
      <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
        <input type="hidden" name="action" value="add_category">
        <label class="text-sm md:col-span-1">Name
          <input name="cat_name" class="border p-2 rounded w-full" required>
        </label>
        <label class="text-sm md:col-span-1">Image
          <input type="file" name="cat_image" accept="image/jpeg,image/png,image/webp" class="border p-2 rounded w-full bg-white">
        </label>
        <label class="text-sm md:col-span-1">Sort
          <input type="number" name="cat_sort" value="0" class="border p-2 rounded w-full">
        </label>
        <label class="text-sm md:col-span-3">Description
          <textarea name="cat_description" rows="2" class="border p-2 rounded w-full" placeholder="e.g. Stone-baked pizzas"></textarea>
        </label>
        <label class="flex items-center gap-2 text-sm md:col-span-3">
          <input type="checkbox" name="cat_active" value="1" checked> Active
        </label>
        <div class="md:col-span-3">
          <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">Add Category</button>
        </div>
      </form>

      <!-- Categories table -->
      <form method="post">
        <input type="hidden" name="action" value="save_cat_sort">
        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Image</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Name</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Sort</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Status</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$categories): ?>
            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No categories yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($categories as $c): ?>
            <tr class="border-t">
              <td class="px-3 py-2">
                <?php if (!empty($c['image_path'])): ?>
                  <img src="<?= htmlspecialchars($u($c['image_path'])) ?>" class="h-10 w-10 object-cover rounded border" alt="">
                <?php else: ?><span class="text-gray-400 text-sm">—</span><?php endif; ?>
              </td>
              <td class="px-3 py-2">
                <a class="text-indigo-700 hover:underline" href="<?= htmlspecialchars($u('menu.php?cat='.(int)$c['id'])) ?>">
                  <?= htmlspecialchars($c['name']) ?>
                </a>
              </td>
              <td class="px-3 py-2 w-28">
                <input type="number" name="sort[<?= (int)$c['id'] ?>]" value="<?= (int)$c['sort_order'] ?>" class="border p-2 rounded w-full">
              </td>
              <td class="px-3 py-2">
                <?php if ($c['active']): ?>
                  <span class="px-2 py-1 text-xs rounded bg-emerald-100 text-emerald-700">Active</span>
                <?php else: ?>
                  <span class="px-2 py-1 text-xs rounded bg-gray-200 text-gray-700">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap gap-2">
                  <a href="<?= htmlspecialchars($u('menu.php?toggle_cat='.(int)$c['id'])) ?>" class="px-3 py-1 rounded-lg border hover:bg-gray-100">
                    <?= $c['active'] ? 'Deactivate' : 'Activate' ?>
                  </a>
                  <a href="<?= htmlspecialchars($u('menu.php?delete_cat='.(int)$c['id'])) ?>" onclick="return confirm('Delete this category and all its items?')" class="px-3 py-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-white">Delete</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($categories): ?>
          <div class="mt-3">
            <button class="px-3 py-2 rounded-lg border hover:bg-gray-100">Save Sort</button>
          </div>
        <?php endif; ?>
      </form>
    </div>

    <!-- Items panel -->
    <div class="bg-white/80 p-6 rounded-2xl shadow border border-gray-100">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold">Items <?= $selectedCatId ? 'in “'.htmlspecialchars(($categories && $selectedCatId) ? (array_values(array_filter($categories, fn($cc)=>$cc['id']==$selectedCatId))[0]['name'] ?? '') : '').'”' : '' ?></h2>
        <form method="get">
          <input type="hidden" name="cat" value="">
          <select name="cat" class="border p-2 rounded" onchange="this.form.submit()">
            <option value="">— Choose category —</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $selectedCatId===$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <!-- Add Item -->
      <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
        <input type="hidden" name="action" value="add_item">
        <input type="hidden" name="item_cat_id" value="<?= (int)$selectedCatId ?>">
        <label class="text-sm md:col-span-2">Item Name
          <input name="item_name" class="border p-2 rounded w-full" required <?= $selectedCatId? '':'disabled' ?>>
        </label>
        <label class="text-sm md:col-span-1">Base Price (£)
          <input name="item_base_price" type="number" step="0.01" class="border p-2 rounded w-full" placeholder="e.g. 9.99" <?= $selectedCatId? '':'disabled' ?>>
        </label>
        <label class="text-sm md:col-span-1">Sort
          <input name="item_sort" type="number" value="0" class="border p-2 rounded w-full" <?= $selectedCatId? '':'disabled' ?>>
        </label>
        <label class="text-sm md:col-span-3">Description
          <textarea name="item_description" rows="2" class="border p-2 rounded w-full" <?= $selectedCatId? '':'disabled' ?>></textarea>
        </label>
        <label class="text-sm md:col-span-1">Image
          <input type="file" name="item_image" accept="image/jpeg,image/png,image/webp" class="border p-2 rounded w-full bg-white" <?= $selectedCatId? '':'disabled' ?>>
        </label>
        <label class="flex items-center gap-2 text-sm md:col-span-4">
          <input type="checkbox" name="item_active" value="1" checked <?= $selectedCatId? '':'disabled' ?>> Active
        </label>
        <div class="md:col-span-4">
          <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg" <?= $selectedCatId? '':'disabled' ?>>Add Item</button>
        </div>
      </form>

      <!-- Items table -->
      <form method="post">
        <input type="hidden" name="action" value="save_item_sort">
        <input type="hidden" name="current_cat" value="<?= (int)$selectedCatId ?>">
        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Image</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Item</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Base £</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Sizes</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Sort</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Status</th>
              <th class="text-left text-sm font-semibold text-gray-600 px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$selectedCatId): ?>
              <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500">Choose a category on the top-right to manage its items.</td></tr>
            <?php elseif (!$items): ?>
              <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500">No items yet in this category.</td></tr>
            <?php else: ?>
              <?php foreach ($items as $it): ?>
                <tr class="border-t align-top">
                  <td class="px-3 py-2">
                    <?php if (!empty($it['image_path'])): ?>
                      <img src="<?= htmlspecialchars($u($it['image_path'])) ?>" class="h-12 w-12 object-cover rounded border" alt="">
                    <?php else: ?><span class="text-gray-400 text-sm">—</span><?php endif; ?>
                  </td>
                  <td class="px-3 py-2">
                    <div class="font-medium"><?= htmlspecialchars($it['name']) ?></div>
                    <div class="text-sm text-gray-600"><?= nl2br(htmlspecialchars($it['description'] ?? '')) ?></div>
                  </td>
                  <td class="px-3 py-2"> <?= $it['base_price']!==null ? '£'.number_format((float)$it['base_price'],2) : '—' ?> </td>
                  <td class="px-3 py-2">
                    <?php
                      $sizes = $sizesByItem[$it['id']] ?? [];
                      if (!$sizes): ?>
                        <span class="text-gray-400 text-sm">None</span>
                      <?php else: ?>
                        <ul class="text-sm text-gray-800 list-disc pl-4">
                          <?php foreach ($sizes as $sz): ?>
                            <li>
                              <?= htmlspecialchars($sz['size_label']) ?> — £<?= number_format((float)$sz['price'],2) ?>
                              <a href="<?= htmlspecialchars($u('menu.php?delete_size='.(int)$sz['id'].'&cat='.(int)$selectedCatId)) ?>"
                                 class="text-rose-600 hover:underline ml-2"
                                 onclick="return confirm('Delete this size?')">delete</a>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      <?php endif; ?>

                    <!-- Add size form -->
                    <form method="post" class="mt-2 flex items-center gap-2">
                      <input type="hidden" name="action" value="add_size">
                      <input type="hidden" name="size_item_id" value="<?= (int)$it['id'] ?>">
                      <input name="size_label" placeholder="Size (e.g. Small)" class="border p-1 rounded">
                      <input name="size_price" type="number" step="0.01" placeholder="Price" class="border p-1 rounded w-28">
                      <button class="px-3 py-1 rounded-lg border hover:bg-gray-100">Add</button>
                    </form>
                  </td>
                  <td class="px-3 py-2 w-24">
                    <input type="number" name="sort[<?= (int)$it['id'] ?>]" value="<?= (int)$it['sort_order'] ?>" class="border p-2 rounded w-full">
                  </td>
                  <td class="px-3 py-2">
                    <?php if ($it['active']): ?>
                      <span class="px-2 py-1 text-xs rounded bg-emerald-100 text-emerald-700">Active</span>
                    <?php else: ?>
                      <span class="px-2 py-1 text-xs rounded bg-gray-200 text-gray-700">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-2">
                    <div class="flex flex-wrap gap-2">
                      <a href="<?= htmlspecialchars($u('menu.php?toggle_item='.(int)$it['id'].'&cat='.(int)$selectedCatId)) ?>" class="px-3 py-1 rounded-lg border hover:bg-gray-100">
                        <?= $it['active'] ? 'Deactivate' : 'Activate' ?>
                      </a>
                      <a href="<?= htmlspecialchars($u('menu.php?delete_item='.(int)$it['id'].'&cat='.(int)$selectedCatId)) ?>"
                         onclick="return confirm('Delete this item?')"
                         class="px-3 py-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-white">Delete</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        <?php if ($items): ?>
          <div class="mt-3">
            <button class="px-3 py-2 rounded-lg border hover:bg-gray-100">Save Sort</button>
          </div>
        <?php endif; ?>
      </form>
    </div>

  </div>
</main>

<!-- Footer -->
<footer class="bg-gray-900 text-gray-300 mt-10">
  <div class="max-w-7xl mx-auto px-4 py-6 flex flex-col sm:flex-row items-center justify-between gap-4">
    <span class="text-sm">&copy; <?= date('Y') ?> LB Admin Panel. All rights reserved.</span>
    <nav class="flex gap-4 text-sm">
      <a href="<?= htmlspecialchars($u('reports.php')) ?>" class="hover:text-white transition">Reports</a>
      <a href="<?= htmlspecialchars($u('settings.php')) ?>" class="hover:text-white transition">Settings</a>
      <a href="<?= htmlspecialchars($u('help.php')) ?>" class="hover:text-white transition">Help</a>
    </nav>
  </div>
</footer>

</body>
</html>
