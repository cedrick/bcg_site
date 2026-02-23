<?php
// pos.php - Full single-file with UoM selectors, in-cart UoM switch (shows alt price),
// pre-check against products.php?ajax=check_sell, and checkout that subtracts base_qty correctly.
//
// Notes:
// - products.stock is assumed to be stored in base units (e.g., meters).
// - products table may have columns: uom (base), alt_uom, alt_factor (numeric), alt_price.
// - products.php must implement ?ajax=check_sell for the external pre-check (this file calls it).
// - Backup your original file before replacing.
 
date_default_timezone_set('Asia/Manila');
require 'auth.php';
require_login();
 
// Suppress warnings in JSON responses; log instead.
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
 
$db = get_db();
try { $db->exec("PRAGMA busy_timeout=5000"); } catch (Exception $e) {}
 
$user = $_SESSION['user']['username'] ?? 'cashier';
 
// ---------------- Access control: forbid Inventory role for POS ----------------
// Deny access to pos.php for users with role "inventory" (case-insensitive).
$session_user = $_SESSION['user'] ?? [];
// normalize possible shapes: 'role' string, or 'roles' array, or ['role'=>'...']
$role_from_string = isset($session_user['role']) ? strtolower(trim((string)$session_user['role'])) : '';
$roles_array = [];
if (!empty($session_user['roles']) && is_array($session_user['roles'])) {
    $roles_array = array_map('strtolower', $session_user['roles']);
} elseif ($role_from_string !== '') {
    $roles_array = [$role_from_string];
}
$is_inventory = in_array('inventory', $roles_array, true) || $role_from_string === 'inventory';
 
if ($is_inventory) {
    // If AJAX (your client-side uses POST + ajax param), return JSON 403 for better UX.
    $is_ajax = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8', true, 403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden: POS access is restricted for your role.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Otherwise show a simple 403 HTML page and stop.
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>403 Forbidden</title>';
    echo '<style>body{font-family:system-ui,Arial;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#fafafa;color:#222} .box{max-width:520px;padding:28px;border-radius:12px;background:white;box-shadow:0 6px 24px rgba(16,24,40,.06);text-align:center} a{color:#0b66bf}</style>';
    echo '</head><body><div class="box"><h1>403 — Forbidden</h1><p>Your account does not have permission to access the POS.</p><p>If you think this is an error, contact an administrator.</p><p><a href="index.php">Return to dashboard</a></p></div></body></html>';
    exit;
}
// ---------------- End forbid block ----------------
 
// ---------------- Permission helper (kept for other checks) ----------------
// Tolerantly read role/cap shapes commonly used. Prefer a capability flag if set.
$role = $_SESSION['user']['role'] ?? (is_array($_SESSION['user']['roles']) && count($_SESSION['user']['roles']) ? $_SESSION['user']['roles'][0] : null);
 
function can_manage_inventory() {
    // Prefer explicit capability flag if present in session user
    if (!empty($_SESSION['user']['caps']) && is_array($_SESSION['user']['caps'])) {
        if (!empty($_SESSION['user']['caps']['manage_inventory'])) return true;
    }
    // Fallback to role names
    $allowed = ['admin', 'store_manager']; // extend this list if you have other inventory roles
    $currentRole = $_SESSION['user']['role'] ?? (is_array($_SESSION['user']['roles']) ? ($_SESSION['user']['roles'][0] ?? null) : null);
    return in_array($currentRole, $allowed, true);
}
// ---------------- End Permission helper ----------------
 
// ensure session cart and discount storage
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
if (!isset($_SESSION['cart_discount_amount'])) $_SESSION['cart_discount_amount'] = 0.0;
 
/* ===== Helpers ===== */
function json_out($payload, $code=200){
    if (function_exists('http_response_code')) http_response_code($code);
    if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function money($n){ return number_format((float)$n, 2, '.', ','); }
function fetch_product($db, $id) {
    $st = $db->prepare('SELECT id, sku, name, price, stock, uom, alt_uom, alt_factor, alt_price FROM products WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
 
/* ===== Safe migrations: sales, sales_items, stock_logs ===== */
function ensure_sales_table_and_columns($db) {
    $db->exec("
      CREATE TABLE IF NOT EXISTS sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
      );
    ");
    $desired = [
        'seller' => "seller TEXT",
        'subtotal' => "subtotal REAL DEFAULT 0",
        'discount_pct' => "discount_pct REAL DEFAULT 0",
        'discount_amount' => "discount_amount REAL DEFAULT 0",
        'subtotal_after_discount' => "subtotal_after_discount REAL DEFAULT 0",
        'vat' => "vat REAL DEFAULT 0",
        'total' => "total REAL DEFAULT 0",
        'paid' => "paid REAL DEFAULT 0",
        'change_amount' => "change_amount REAL DEFAULT 0",
        'vat_enabled' => "vat_enabled INTEGER DEFAULT 0"
    ];
    $existing = [];
    try {
        $stmt = $db->query("PRAGMA table_info('sales')");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $existing[$c['name']] = true;
    } catch (Exception $e) {}
    foreach ($desired as $col => $definition) {
        if (!isset($existing[$col])) {
            try { $db->exec("ALTER TABLE sales ADD COLUMN {$definition}"); } catch (Exception $e) {}
        }
    }
}
ensure_sales_table_and_columns($db);
 
try {
    $db->exec("CREATE TABLE IF NOT EXISTS sales_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        product_id INTEGER,
        sku TEXT,
        name TEXT,
        qty REAL,
        uom TEXT,
        unit_price REAL,
        line_total REAL
    );");
    $db->exec("CREATE TABLE IF NOT EXISTS stock_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        old_stock REAL,
        new_stock REAL,
        qty_changed REAL,
        action_by TEXT,
        note TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );");
} catch (Exception $e) {}
 
/* ===== UoM helpers ===== */
// Compute base quantity (base unit) for a requested qty in given UoM
function compute_base_qty(array $product, float $qty, string $uom) : float {
    $base = $product['uom'] ?? '';
    $alt = $product['alt_uom'] ?? null;
    $factor = floatval($product['alt_factor'] ?? 1) ?: 1.0;
    if ($uom === $base || empty($alt)) return $qty;
    if ($uom === $alt) return $qty * $factor;
    return $qty;
}
// Validate whether the requested qty in uom can be sold given stock (stock in base units)
function can_sell(array $product, float $qty, string $uom){
    $stock_base = floatval($product['stock'] ?? 0);
    $base = $product['uom'] ?? '';
    $alt = $product['alt_uom'] ?? null;
    $factor = floatval($product['alt_factor'] ?? 1) ?: 1.0;
 
    // if selling in alt (e.g., roll) we typically require integer roll qty
    if ($alt && $uom === $alt) {
        if (intval($qty) != $qty) {
            return ['ok'=>false, 'error'=>'Cannot sell fractional '.$alt.'. Use base unit ('.$base.') for partial quantities.'];
        }
        $available_full = floor($stock_base / $factor);
        if ($qty > $available_full) {
            return ['ok'=>false, 'error'=>"Insufficient full {$alt}s. Available: {$available_full}"];
        }
        return ['ok'=>true, 'base_qty'=> $qty * $factor];
    }
 
    // selling in base or other -> convert and check
    $base_qty = compute_base_qty($product, $qty, $uom);
    if ($base_qty > $stock_base + 0.000001) {
        return ['ok'=>false, 'error'=>'Insufficient stock (base unit: '.$base.').'];
    }
    return ['ok'=>true, 'base_qty'=> $base_qty];
}
 
/* ===== Totals & render cart ===== */
function compute_totals($cart) {
    $subtotal = 0.0;
    foreach ($cart as $c) $subtotal += floatval($c['price']) * floatval($c['qty']);
    $discount_amount = floatval($_SESSION['cart_discount_amount'] ?? 0.0);
    $discount_amount = max(0, min($subtotal, $discount_amount));
    $subtotal_after_discount = round($subtotal - $discount_amount, 2);
    $vat_enabled = !empty($_SESSION['vat_enabled']);
    $vat = $vat_enabled ? round($subtotal_after_discount * 0.12, 2) : 0.0;
    $total = round($subtotal_after_discount + $vat, 2);
    return [
        'subtotal' => round($subtotal,2),
        'discount_amount' => $discount_amount,
        'subtotal_after_discount' => $subtotal_after_discount,
        'vat' => $vat,
        'total' => $total,
        'vat_enabled' => $vat_enabled
    ];
}
 
function render_cart_html($cart) {
    $t = compute_totals($cart);
    ob_start(); ?>
    <div class="card-body p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0" style="color:var(--brand);font-weight:700">Cart</h6>
        <div><a class="btn btn-link btn-sm p-0" href="?clear=1">Clear</a></div>
      </div>
 
      <?php if (empty($cart)): ?>
        <div class="text-muted">Cart is empty. Add products to begin.</div>
      <?php else: ?>
        <div id="cartInner" class="table-responsive">
          <table class="table table-borderless align-middle mb-2" style="table-layout:auto;">
            <thead>
              <tr>
                <th style="min-width:150px">Item</th>
                <th class="text-end" style="width:160px">Qty / UoM</th>
                <th class="text-end" style="width:120px">Unit</th>
                <th class="text-end" style="width:120px">Total</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($cart as $cid => $c):
                $line = floatval($c['price']) * floatval($c['qty']);
                $row_uom = isset($c['uom']) ? $c['uom'] : '';
                $prod = null;
                try { $prod = fetch_product($GLOBALS['db'], intval($c['id'])); } catch(Exception $e){}
                $base_uom = $prod['uom'] ?? '';
                $alt_uom = $prod['alt_uom'] ?? '';
            ?>
              <tr data-cart-row="<?= htmlspecialchars($cid) ?>" data-uom="<?= htmlspecialchars($row_uom) ?>">
                <td style="min-width:150px">
                  <div style="font-weight:600"><?= htmlspecialchars($c['name']) ?> <?php if ($row_uom): ?><span class="small-note text-muted"> (<?= htmlspecialchars($row_uom) ?>)</span><?php endif; ?></div>
                  <?php if (!empty($c['sku'])): ?><div class="small-note text-muted"><?= htmlspecialchars($c['sku']) ?></div><?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="d-flex justify-content-end align-items-center gap-2">
                    <input type="number" class="form-control form-control-sm cart-qty" name="qty[<?= htmlspecialchars($cid) ?>]" data-pid="<?= htmlspecialchars($cid) ?>" value="<?= htmlspecialchars($c['qty']) ?>" min="0" style="width:78px;">
                    <select class="form-select form-select-sm line-uom" data-pid="<?= htmlspecialchars($cid) ?>" style="width:110px;">
                      <option value="<?= htmlspecialchars($base_uom) ?>" <?= $row_uom === $base_uom ? 'selected' : '' ?>><?= $base_uom ?: 'unit' ?></option>
                      <?php if (!empty($alt_uom)): ?>
                        <option value="<?= htmlspecialchars($alt_uom) ?>" <?= $row_uom === $alt_uom ? 'selected' : '' ?>><?= htmlspecialchars($alt_uom) ?></option>
                      <?php endif; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-danger remove-item" data-pid="<?= htmlspecialchars($cid) ?>" title="Remove item">Remove</button>
                  </div>
                </td>
                <td class="text-end"><span class="small-note">₱</span> <span class="unit-price" data-pid="<?= htmlspecialchars($cid) ?>"><?= money($c['price']) ?></span></td>
                <td class="text-end"><span class="peso">₱</span> <?= money($line) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
 
        <div class="row gx-2 gy-2 align-items-center">
          <div class="col-7">
            <label class="form-label small mb-1">Discount (₱)</label>
            <input id="discountAmount" class="form-control form-control-sm" type="number" min="0" step="0.01" value="<?= htmlspecialchars($t['discount_amount']) ?>">
            <div class="small text-muted mt-1">Enter flat discount amount to subtract from subtotal.</div>
          </div>
          <div class="col-5 text-end">
            <div class="small-note">Discount</div>
            <div style="font-weight:700">- ₱ <span id="discountAmount"><?= money($t['discount_amount']) ?></span></div>
          </div>
        </div>
 
        <hr class="my-2">
 
        <div class="d-flex justify-content-between small-note">
          <div>Subtotal</div>
          <div><span class="peso">₱</span> <span id="subtotalVal"><?= money($t['subtotal']) ?></span></div>
        </div>
        <div class="d-flex justify-content-between small-note mt-1">
          <div>Subtotal after discount</div>
          <div><span class="peso">₱</span> <span id="subtotalAfterDiscountVal"><?= money($t['subtotal_after_discount']) ?></span></div>
        </div>
        <div class="d-flex justify-content-between small-note mt-1">
          <div>VAT (12%)</div>
          <div><span class="peso">₱</span> <span id="vatVal"><?= money($t['vat']) ?></span></div>
        </div>
        <div class="d-flex justify-content-between fw-bold mt-2">
          <div>Total</div>
          <div><span class="peso">₱</span> <span id="totalVal"><?= money($t['total']) ?></span></div>
        </div>
 
        <div class="mt-3">
          <label class="form-label small mb-1">Enter Amount</label>
          <input id="amountGiven" class="form-control enter-amount" type="number" step="0.01" min="0" placeholder="0.00">
        </div>
 
        <div class="d-flex justify-content-between align-items-center mt-2">
          <div class="small-note">Change</div>
          <div id="changeDisplay" style="font-weight:700">₱ 0.00</div>
        </div>
 
        <div class="mt-3 d-grid gap-2">
          <button id="checkoutBtn" class="btn btn-accent btn-sm btn-sm-consistent">Checkout</button>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
 
/* ===== AJAX handlers (before HTML output) ===== */
function ajax_response_cart() {
    $html = render_cart_html($_SESSION['cart']);
    $tot = compute_totals($_SESSION['cart']);
    json_out([
        'ok'=>true,
        'html'=>$html,
        'subtotal'=>$tot['subtotal'],
        'discount_amount'=>$tot['discount_amount'],
        'subtotal_after_discount'=>$tot['subtotal_after_discount'],
        'vat'=>$tot['vat'],
        'total'=>$tot['total'],
        'vat_enabled'=>$tot['vat_enabled']
    ]);
}
 
// Add item (supports UoM)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'add') {
    $pid = intval($_POST['product_id'] ?? 0);
    $qty = max(1, floatval($_POST['qty'] ?? 1));
    $uom = trim($_POST['uom'] ?? '');
    $p = fetch_product($db, $pid);
    if (!$p) json_out(['ok'=>false,'error'=>'Product not found']);
    $stock = max(0.0, floatval($p['stock']));
    if ($stock <= 0) json_out(['ok'=>false,'error'=>'Out of stock']);
    $already = isset($_SESSION['cart'][$pid]) ? floatval($_SESSION['cart'][$pid]['qty']) : 0.0;
    $free = max(0.0, $stock - $already);
    if ($free <= 0) json_out(['ok'=>false,'error'=>'No more stock available for this item']);
 
    if ($uom !== '') {
        $chk = can_sell($p, $qty, $uom);
        if (!$chk['ok']) json_out(['ok'=>false,'error'=>$chk['error']]);
    } else {
        $uom = $p['uom'] ?? '';
    }
 
    // Determine unit price based on selected UoM
    if ($uom === ($p['alt_uom'] ?? null) && strlen($p['alt_price'] ?? '') > 0) {
        $unit_price = floatval($p['alt_price']);
    } else {
        $unit_price = floatval($p['price'] ?? 0);
    }
 
    $stored = ['id'=>$p['id'],'sku'=>$p['sku'],'name'=>$p['name'],'price'=>$unit_price,'qty'=>$qty,'uom'=>$uom];
 
    if (isset($_SESSION['cart'][$pid])) {
        $_SESSION['cart'][$pid]['qty'] += $qty;
        $_SESSION['cart'][$pid]['uom'] = $uom;
        $_SESSION['cart'][$pid]['price'] = $unit_price;
    } else {
        $_SESSION['cart'][$pid] = $stored;
    }
 
    $remaining = $stock - floatval($_SESSION['cart'][$pid]['qty']);
    $html = render_cart_html($_SESSION['cart']);
    $tot = compute_totals($_SESSION['cart']);
    json_out([
        'ok'=>true,
        'remaining_stock'=>$remaining,
        'product_id'=>$pid,
        'html'=>$html,
        'subtotal'=>$tot['subtotal'],
        'discount_amount'=>$tot['discount_amount'],
        'subtotal_after_discount'=>$tot['subtotal_after_discount'],
        'vat'=>$tot['vat'],
        'total'=>$tot['total'],
        'vat_enabled'=>$tot['vat_enabled']
    ]);
}
 
// Change UoM for cart line
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'change_uom') {
    $pid = intval($_POST['pid'] ?? 0);
    $uom = trim($_POST['uom'] ?? '');
    if (!$pid || !isset($_SESSION['cart'][$pid])) {
        json_out(['ok'=>false,'error'=>'Cart line not found'], 400);
    }
    $p = fetch_product($db, $pid);
    if (!$p) json_out(['ok'=>false,'error'=>'Product not found'], 400);
 
    if ($uom === ($p['alt_uom'] ?? null) && strlen($p['alt_price'] ?? '') > 0) {
        $unit_price = floatval($p['alt_price']);
    } else {
        $unit_price = floatval($p['price'] ?? 0);
    }
 
    $_SESSION['cart'][$pid]['uom'] = $uom;
    $_SESSION['cart'][$pid]['price'] = $unit_price;
 
    ajax_response_cart();
}
 
// Update quantities & discount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'update') {
    foreach ($_POST['qty'] ?? [] as $pid => $q) {
        $pid = intval($pid); $q = floatval($q);
        if (!isset($_SESSION['cart'][$pid])) continue;
        if ($q <= 0) { unset($_SESSION['cart'][$pid]); continue; }
        $p = fetch_product($db, $pid);
        if ($p) {
            $stock = max(0.0, floatval($p['stock']));
            if ($q > $stock) $q = $stock;
            if ($q <= 0) { unset($_SESSION['cart'][$pid]); continue; }
        }
        $_SESSION['cart'][$pid]['qty'] = $q;
    }
    if (isset($_POST['discount_amount'])) {
        $d = floatval($_POST['discount_amount']);
        $_SESSION['cart_discount_amount'] = max(0, $d);
    }
    ajax_response_cart();
}
 
// Remove item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'remove') {
    $pid = intval($_POST['pid'] ?? 0);
    if ($pid && isset($_SESSION['cart'][$pid])) unset($_SESSION['cart'][$pid]);
    ajax_response_cart();
}
 
// Toggle VAT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'toggle_vat') {
    $_SESSION['vat_enabled'] = !(!empty($_SESSION['vat_enabled']));
    ajax_response_cart();
}
 
// Stocks (refresh)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'stocks') {
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
    if (empty($ids)) json_out(['ok'=>true,'stocks'=>[]]);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, stock FROM products WHERE id IN ($in)");
    $stmt->execute($ids);
    $stocks = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $stocks[intval($r['id'])] = floatval($r['stock']);
    json_out(['ok'=>true,'stocks'=>$stocks]);
}
 
/* ===== CHECKOUT (AJAX) =====
   - Pre-check is performed on client calling products.php?ajax=check_sell for each line.
   - Server re-validates with can_sell() and subtracts base_qty accordingly if permitted.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'checkout') {
    $cart_snapshot = $_SESSION['cart'] ?? [];
    $vat_enabled_snapshot = !empty($_SESSION['vat_enabled']);
    $discount_amount_snapshot = floatval($_SESSION['cart_discount_amount'] ?? 0.0);
 
    if (empty($cart_snapshot)) json_out(['ok'=>false,'error'=>'Cart is empty'], 400);
 
    // compute totals
    $subtotal = 0.0;
    foreach ($cart_snapshot as $c) $subtotal += floatval($c['price']) * floatval($c['qty']);
    $discount_amount = round(min($subtotal, $discount_amount_snapshot), 2);
    $subtotal_after_discount = round($subtotal - $discount_amount, 2);
    $vat_amount = $vat_enabled_snapshot ? round($subtotal_after_discount * 0.12, 2) : 0.0;
    $total_due = round($subtotal_after_discount + $vat_amount, 2);
 
    $amount_given = floatval($_POST['amount_given'] ?? 0);
    $change = round($amount_given - $total_due, 2);
 
    if ($amount_given < $total_due) {
        json_out(['ok'=>false,'error'=>'Amount given is less than the total due.','total_due'=>$total_due,'amount_given'=>$amount_given], 400);
    }
 
    // Determine whether we are allowed to update inventory. If not, we will still record the sale but skip stock changes.
    $inventory_allowed = can_manage_inventory();
    $inventory_note = $inventory_allowed ? '' : 'Inventory updates skipped for this sale: user lacks inventory permission.';
 
    if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
 
    try {
        $db->beginTransaction();
 
        // Insert sale header
        $now = date('Y-m-d H:i:s');
        $discount_pct = 0.0;
        $ins = $db->prepare('INSERT INTO sales(created_at,seller,subtotal,discount_pct,discount_amount,subtotal_after_discount,vat,total,paid,change_amount,vat_enabled) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $now, $user, $subtotal, $discount_pct, $discount_amount,
            $subtotal_after_discount, $vat_amount, $total_due,
            $amount_given, $change, $vat_enabled_snapshot ? 1 : 0
        ]);
        $sale_id = $db->lastInsertId();
 
        $insItem = $db->prepare('INSERT INTO sales_items(sale_id,product_id,sku,name,qty,uom,unit_price,line_total) VALUES(?,?,?,?,?,?,?,?)');
        $updateStock = $db->prepare('UPDATE products SET stock = ? WHERE id = ?');
        $insertLog = $db->prepare('INSERT INTO stock_logs(product_id,old_stock,new_stock,qty_changed,action_by,note,created_at) VALUES(?,?,?,?,?,?,?)');
 
        foreach ($cart_snapshot as $pid => $c) {
            $pid_int = intval($c['id'] ?? $pid);
            $qty = floatval($c['qty']);
            $uom = isset($c['uom']) ? trim($c['uom']) : '';
 
            $product = fetch_product($db, $pid_int);
            if (!$product) throw new Exception("Product not found (id {$pid_int})");
 
            $use_uom = $uom ?: ($product['uom'] ?? '');
            $chk = can_sell($product, $qty, $use_uom);
            if (!$chk['ok']) throw new Exception("Cannot sell product {$product['sku']}: " . $chk['error']);
            $base_qty = floatval($chk['base_qty']);
 
            // use alt_price if selling by alt_uom and alt_price exists
            if ($use_uom === ($product['alt_uom'] ?? null) && $product['alt_price'] !== null && $product['alt_price'] !== '') {
                $unit_price_final = floatval($product['alt_price']);
            } else {
                $unit_price_final = floatval($product['price'] ?? 0);
            }
 
            $line_total = round(floatval($unit_price_final) * $qty, 2);
 
            // If inventory changes are allowed, subtract base_qty from stock and log it.
            if ($inventory_allowed) {
                $st = $db->prepare('SELECT stock FROM products WHERE id=? LIMIT 1');
                $st->execute([$pid_int]);
                $old_stock = floatval($st->fetchColumn() ?: 0.0);
                $new_stock = $old_stock - $base_qty;
                if ($new_stock < -0.000001) throw new Exception("Stock inconsistency for product {$product['sku']}. Requested base_qty: {$base_qty}, available: {$old_stock}");
 
                $updateStock->execute([$new_stock, $pid_int]);
                $insertLog->execute([$pid_int, $old_stock, $new_stock, -1.0 * $base_qty, $user, "Sale #{$sale_id} ({$qty} {$use_uom})", date('Y-m-d H:i:s')]);
            } else {
                // Inventory skipped: do not update products.stock or insert stock_logs.
                // Optionally you may want to insert a short log elsewhere or notify admins.
            }
 
            $insItem->execute([$sale_id, $pid_int, $product['sku'], $product['name'], $qty, $use_uom, $unit_price_final, $line_total]);
        }
 
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        json_out(['ok'=>false,'error'=>'Failed to save sale: '.$e->getMessage()], 500);
    }
 
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $_SESSION['cart'] = [];
    $_SESSION['cart_discount_amount'] = 0.0;
 
    $receipt = [
        'sale_id' => $sale_id,
        'seller' => $user,
        'timestamp' => date('Y-m-d H:i:s'),
        'items' => $cart_snapshot,
        'subtotal' => round($subtotal,2),
        'discount_amount' => round($discount_amount,2),
        'subtotal_after_discount' => round($subtotal_after_discount,2),
        'vat_amount' => round($vat_amount,2),
        'total_due' => round($total_due,2),
        'amount_given' => round($amount_given,2),
        'change' => round($change,2),
        'vat_enabled' => $vat_enabled_snapshot,
        'inventory_skipped' => !$inventory_allowed,
        'inventory_note' => $inventory_note
    ];
 
    json_out(['ok'=>true,'receipt'=>$receipt]);
}
 
/* ===== Product search endpoint (for instant search) ===== */
function search_products($db, $q) {
    $q = trim($q ?? '');
    $params = [];
    $sql = "SELECT p.id, p.sku, p.name, p.price, p.stock, p.uom, p.alt_uom, p.alt_factor, p.alt_price, 
            COALESCE(SUM(CAST(si.qty AS REAL)), 0) AS qty_sold
            FROM products p
            LEFT JOIN sales_items si ON si.product_id = p.id
            LEFT JOIN sales s ON s.id = si.sale_id AND s.created_at >= datetime('now','-30 days','localtime')";
    if ($q !== '') {
        $tokens = preg_split('/\s+/', $q);
        $wheres = [];
        foreach ($tokens as $t) {
            $wheres[] = "(p.name LIKE ? OR p.sku LIKE ?)";
            $like = "%{$t}%";
            $params[] = $like; $params[] = $like;
        }
        $sql .= " WHERE " . implode(" AND ", $wheres);
    }
    $sql .= " GROUP BY p.id ORDER BY qty_sold DESC, p.name ASC LIMIT 500";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($products)) {
        // Fallback for MySQL
        $params = [];
        $sql = "SELECT p.id, p.sku, p.name, p.price, p.stock, p.uom, p.alt_uom, p.alt_factor, p.alt_price, 
                COALESCE(SUM(si.qty), 0) AS qty_sold
                FROM products p
                LEFT JOIN sales_items si ON si.product_id = p.id
                LEFT JOIN sales s ON s.id = si.sale_id AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if ($q !== '') {
            $tokens = preg_split('/\s+/', $q);
            $wheres = [];
            foreach ($tokens as $t) {
                $wheres[] = "(p.name LIKE ? OR p.sku LIKE ?)";
                $like = "%{$t}%";
                $params[] = $like; $params[] = $like;
            }
            $sql .= " WHERE " . implode(" AND ", $wheres);
        }
        $sql .= " GROUP BY p.id ORDER BY qty_sold DESC, p.name ASC LIMIT 500";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $products;
}
function render_products_html($products){
    ob_start(); ?>
    <div id="productsGrid" class="row row-cols-1 row-cols-sm-2 row-cols-md-2 g-3">
      <?php foreach ($products as $prod): ?>
      <?php
        $pid = intval($prod['id']);
        $stk = floatval($prod['stock']);
        $isZero = $stk <= 0;
        $base_uom = $prod['uom'] ?? '';
        $alt_uom = $prod['alt_uom'] ?? '';
      ?>
      <div class="col">
        <div class="product-card h-100 d-flex flex-column" data-id="<?= $pid ?>" data-name="<?= htmlspecialchars(strtolower($prod['name'])) ?>" data-sku="<?= htmlspecialchars(strtolower($prod['sku'])) ?>" data-base-price="<?= $prod['price'] ?>" data-alt-price="<?= $prod['alt_price'] ?? 0 ?>" data-alt-uom="<?= htmlspecialchars($alt_uom) ?>">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="small-note"><?= htmlspecialchars($prod['sku']) ?></div>
            <div class="small-note">
              <span class="badge stock-badge <?= $isZero ? 'zero' : '' ?>" style="background:transparent;color:var(--brand)"><span class="stock-val"><?= intval($stk) ?></span> in</span>
            </div>
          </div>
          <div class="mb-2" style="flex:1">
            <div style="font-weight:700;font-size:1rem;"><?= htmlspecialchars($prod['name']) ?></div>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <div class="price">₱ <?= money($prod['price']) ?></div>
            <form class="d-flex gap-2 align-items-center add-to-cart-form <?= $isZero ? 'add-disabled' : '' ?>" onsubmit="return addToCartAjax(event);">
              <input type="hidden" name="product_id" value="<?= $pid ?>">
              <input name="qty" type="number" min="1" value="1" class="form-control form-control-sm" style="width:78px" <?= $isZero ? 'disabled' : '' ?>>
              <select name="uom" class="form-select form-select-sm" style="width:110px;">
                <option value="<?= htmlspecialchars($base_uom) ?>"><?= $base_uom ?: 'unit' ?></option>
                <?php if (!empty($alt_uom)): ?>
                  <option value="<?= htmlspecialchars($alt_uom) ?>"><?= htmlspecialchars($alt_uom) ?></option>
                <?php endif; ?>
              </select>
              <button class="btn btn-accent btn-sm btn-sm-consistent" type="submit" <?= $isZero ? 'disabled' : '' ?>>Add</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($products) === 0): ?>
      <div id="emptyState" class="mt-3 text-muted">No products found for your search.</div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
if (($_GET['ajax'] ?? '') === 'products') {
    $raw_q = $_GET['q'] ?? '';
    $products = search_products($db, $raw_q);
    json_out(['ok'=>true,'count'=>count($products),'html'=>render_products_html($products)]);
}
 
/* ===== Non-AJAX: clear / sales view / safe redirects ===== */
function safe_redirect_path($to){
    $allow = ['index.php','products.php','sales_report.php','stocks_report.php','users.php','quotations.php','pos.php'];
    $base = basename($to);
    return in_array($base, $allow, true) ? $base : null;
}
 
if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    $_SESSION['cart'] = [];
    $_SESSION['cart_discount_amount'] = 0.0;
    $redir = isset($_GET['redirect']) ? safe_redirect_path($_GET['redirect']) : null;
    if ($redir) { header('Location: ' . $redir); exit; }
    header('Location: pos.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    exit;
}
 
if (isset($_GET['view']) && $_GET['view'] === 'sales') {
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $from = $_GET['from'] ?? null; $to = $_GET['to'] ?? null; $params = [];
        $sql = "SELECT * FROM sales WHERE 1=1";
        if ($from) { $sql .= " AND date(created_at) >= date(?)"; $params[]=$from; }
        if ($to) { $sql .= " AND date(created_at) <= date(?)"; $params[]=$to; }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $db->prepare($sql); $stmt->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=sales_'.date('Ymd_His').'.csv');
        $out = fopen('php://output','w');
        fputcsv($out, ['id','seller','subtotal','discount_pct','discount_amount','subtotal_after_discount','vat','total','paid','change','vat_enabled','created_at']);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$r['id'],$r['seller'],$r['subtotal'],$r['discount_pct'],$r['discount_amount'],$r['subtotal_after_discount'],$r['vat'],$r['total'],$r['paid'],$r['change_amount'],$r['vat_enabled'],$r['created_at']]);
        }
        exit;
    }
}
 
/* ===== Render page ===== */
$brand_primary = '#0b66bf';
$brand_accent = '#fc3503';
$brand_light = '#f5f8ff';
$vat_enabled = !empty($_SESSION['vat_enabled']);
$raw_q = $_GET['q'] ?? '';
$products = search_products($db, $raw_q);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>POS • Fusion I.T. Solutions</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{--brand:<?= $brand_primary ?>; --accent:<?= $brand_accent ?>; --brand-light:<?= $brand_light ?>; --muted:#6c757d;}
    *{box-sizing:border-box;}
    html,body{width:100%;max-width:100%;overflow-x:hidden;}
    body{background:var(--brand-light);color:#222;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial;margin:0;}
    img{max-width:100%;height:auto;}
    .topbar{background:linear-gradient(90deg,#fff 0%,var(--brand-light) 100%);padding:10px;border-radius:10px;box-shadow:0 2px 8px rgba(16,24,40,0.06);align-items:center;gap:10px;flex-wrap:wrap;}
    .brand{display:flex;align-items:center;gap:.65rem;min-width:0;flex:1 1 auto;}
    .brand img{height:44px;max-height:44px;max-width:160px;object-fit:contain;display:block}
    .brand .title{font-size:1rem;font-weight:700;color:var(--brand);line-height:1}
    .brand .subtitle{font-size:.78rem;color:var(--muted);line-height:1}
    .product-card{border-radius:12px;box-shadow:0 6px 18px rgba(11,102,191,0.06);background:white;padding:14px;height:100%;transition:transform .12s ease, box-shadow .12s ease}
    .product-card:hover{transform:translateY(-6px);box-shadow:0 10px 30px rgba(11,102,191,0.08)}
    .price {font-weight:700;color:var(--brand)}
    .small-note{font-size:.85rem;color:var(--muted)}
    .enter-amount {height: calc(2.35rem + 2px); padding:.375rem .5rem; font-size:.95rem;}
    .btn-sm-consistent {height: calc(2.35rem + 2px); padding:.375rem .65rem; font-size:.875rem;}
    .btn-accent { background: var(--accent); border-color: var(--accent); color: #fff; }
    .btn-accent:hover { filter: brightness(0.95); }
    .peso { margin-right:6px; font-weight:700; color:var(--muted); }
    table.table td, table.table th { vertical-align: middle; white-space: nowrap; }
    .table-borderless tbody tr td { padding-top: .55rem; padding-bottom: .55rem; }
    .cart-area { min-height: 120px; }
    .stock-badge.zero { color:#b02a37 !important; }
    .add-disabled { pointer-events:none; opacity:.6; }
    #searchInput{max-width:100%;width:320px;}
    #productsGrid{width:100%;}
    .product-card .add-to-cart-form{flex-wrap:wrap;}
    .product-card .add-to-cart-form .form-control,
    .product-card .add-to-cart-form .form-select{flex:1 1 auto;min-width:86px;}
    .product-card .price{white-space:nowrap;}
    #cartInner table{min-width:360px;}
    .table-responsive{overflow-x:auto;}
    @media (min-width: 992px){
      .cart-area{position:sticky;top:16px;}
    }
    @media (max-width: 991px) {
      .cart-area {position:static;margin-top:14px;}
      #searchInput{width:100%;min-width:0;}
    }
    @media (max-width: 767.98px){
      .topbar{padding:12px;}
      .topbar .d-flex.gap-2{width:100%;flex-wrap:wrap;justify-content:flex-start;}
      .topbar .small.text-muted{width:100%;}
      .product-card{padding:12px;}
      .product-card .d-flex.justify-content-between.align-items-center{flex-wrap:wrap;gap:8px;}
      #productsGrid{margin-left:0;margin-right:0;}
      #cartCard .d-flex.justify-content-end.align-items-center.gap-2{flex-wrap:wrap;}
    }
    @media (max-width: 575.98px){
      .brand .title{font-size:.95rem;}
      .brand .subtitle{font-size:.72rem;}
      .topbar .btn{width:100%;}
      .topbar form{width:100%;}
      .product-card .price{font-size:.95rem;}
      #cartInner table{min-width:320px;}
      .cart-area .row.gx-2{flex-direction:column;}
      .cart-area .col-7,.cart-area .col-5{text-align:left;width:100%;}
    }
  </style>
</head>
<body>
<div class="container py-3">
  <div class="d-flex justify-content-between mb-3 topbar">
    <div class="d-flex brand">
      <?php if (file_exists(__DIR__.'/assets/logo.png')): ?>
        <img src="assets/logo.png" alt="Fusion logo">
      <?php else: ?>
        <div style="width:44px;height:44px;border-radius:8px;background:var(--brand);display:flex;align-items:center;justify-content:center;color:white;font-weight:700">F</div>
      <?php endif; ?>
      <div>
        <div class="title">Fusion I.T. Solutions Store</div>
        <div class="subtitle">Point of Sale</div>
      </div>
    </div>
 
    <div class="d-flex gap-2 align-items-center">
      <form method="get" id="searchForm" style="margin:0">
        <input id="searchInput" name="q" class="form-control form-control-sm" placeholder="Search product name or SKU" style="min-width:320px" value="<?= htmlspecialchars($raw_q) ?>" autocomplete="off">
      </form>
 
      <div class="small text-muted">Seller: <strong><?= htmlspecialchars($user) ?></strong></div>
 
      <button id="vatToggleBtn" class="btn btn-accent btn-sm btn-sm-consistent" title="Toggle 12% VAT" data-vat="<?= $vat_enabled ? '1' : '0' ?>">
        VAT <?= $vat_enabled ? 'On' : 'Off' ?>
      </button>
 
      <a class="btn btn-accent btn-sm btn-sm-consistent" href="pos.php?clear=1&redirect=index.php" aria-label="Go to Dashboard">Dashboard</a>
  <a class="btn btn-accent btn-sm btn-sm-consistent" href="pos.php?clear=1&redirect=sales_report.php" aria-label="Sales Report">Sales Report</a>
  </div>
  </div>
 
<?php if (isset($_GET['view']) && $_GET['view'] === 'sales'):
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM sales WHERE date(created_at) BETWEEN date(?) AND date(?) ORDER BY created_at DESC");
    $stmt->execute([$from,$to]);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Sales Report</h5>
        <div class="d-flex gap-2">
          <form method="get" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="view" value="sales">
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control form-control-sm">
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control form-control-sm">
            <button class="btn btn-primary btn-sm">Filter</button>
          </form>
          <a class="btn btn-outline-secondary btn-sm" href="?view=sales&export=csv&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">Export CSV</a>
        </div>
      </div>
 
      <div class="table-responsive">
        <table class="table table-striped">
          <thead><tr><th>ID</th><th>Seller</th><th>Date</th><th>Subtotal</th><th>Disc %</th><th>Disc amt</th><th>VAT</th><th>Total</th><th>Paid</th><th>Change</th></tr></thead>
        <tbody>
            <?php foreach ($sales as $s): ?>
              <tr>
                <td><?= intval($s['id']) ?></td>
                <td><?= htmlspecialchars($s['seller']) ?></td>
                <td><?= htmlspecialchars($s['created_at']) ?></td>
                <td>₱ <?= money($s['subtotal']) ?></td>
                <td><?= money($s['discount_pct']) ?>%</td>
                <td>₱ <?= money($s['discount_amount']) ?></td>
                <td>₱ <?= money($s['vat']) ?></td>
                <td>₱ <?= money($s['total']) ?></td>
                <td>₱ <?= money($s['paid']) ?></td>
                <td>₱ <?= money($s['change_amount']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($sales) === 0): ?>
              <tr><td colspan="10" class="text-muted">No sales in this range.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
 
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="pos.php">Back to POS</a>
    <a class="btn btn-outline-secondary btn-sm" href="pos.php?clear=1&redirect=index.php">Dashboard</a>
  </div>
 
<?php else: ?>
 
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 style="color:var(--brand)">Products</h5>
      </div>
 
      <div id="productsContainer">
        <?= render_products_html($products) ?>
      </div>
    </div>
 
    <div class="col-lg-4">
      <div class="card cart-area" id="cartCard">
        <?= render_cart_html($_SESSION['cart']) ?>
      </div>
    </div>
  </div>
 
<?php endif; ?>
 
  <footer class="text-muted small mt-4">Fusion I.T. Solutions • POS</footer>
</div>
 
<!-- Receipt modal -->
<div class="modal fade" id="postCheckoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Sale Completed</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="postCheckoutBody"></div>
      <div class="modal-footer">
        <button id="proceedBtn" type="button" class="btn btn-secondary" data-bs-dismiss="modal">Proceed</button>
        <button id="printReceiptBtn" type="button" class="btn btn-accent">Print Receipt</button>
      </div>
    </div>
  </div>
</div>
 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Client JS: posts & utility */
async function postFormData(url, fd) {
  const resp = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
  const text = await resp.text();
  if (!resp.ok) { throw new Error(`HTTP ${resp.status}: ${text.slice(0, 500)}`); }
  try { return JSON.parse(text); } catch (e) { throw new Error(`Invalid JSON from server: ${text.slice(0, 500)}`); }
}
function moneyFmt(n){ return '₱ ' + Number(n).toFixed(2); }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
 
function setProductStockUI(pid, stock){
  const card = document.querySelector('.product-card[data-id="'+pid+'"]');
  if (!card) return;
  const badge = card.querySelector('.stock-val'); if (badge) badge.textContent = parseInt(stock);
  const badgeWrap = card.querySelector('.stock-badge');
  if (badgeWrap) (parseInt(stock) <= 0) ? badgeWrap.classList.add('zero') : badgeWrap.classList.remove('zero');
  const form = card.querySelector('.add-to-cart-form');
  const qtyInput = form?.querySelector('input[name="qty"]');
  const addBtn = form?.querySelector('button[type="submit"]');
  const zero = parseInt(stock) <= 0;
  if (form) form.classList.toggle('add-disabled', zero);
  if (qtyInput) qtyInput.disabled = zero;
  if (addBtn) addBtn.disabled = zero;
}
 
/* Add to cart (reads uom from selector) */
async function addToCartAjax(e){
  e.preventDefault();
  const form = e.currentTarget;
  const pid = form.querySelector('input[name="product_id"]').value;
  const qty = form.querySelector('input[name="qty"]').value || 1;
  const uomSel = form.querySelector('select[name="uom"]');
  const uom = uomSel ? uomSel.value : '';
  const fd = new FormData();
  fd.append('ajax','add'); fd.append('product_id', pid); fd.append('qty', qty);
  if (uom) fd.append('uom', uom);
  try {
    const res = await postFormData('pos.php', fd);
    if (res.ok) {
      document.getElementById('cartCard').innerHTML = res.html;
      bindCartInteractions();
      updateTotalsFromResponse(res);
      if (typeof res.remaining_stock !== 'undefined' && typeof res.product_id !== 'undefined') {
        setProductStockUI(res.product_id, res.remaining_stock);
      }
    } else {
      alert('Add failed: ' + (res.error || 'unknown'));
    }
  } catch (err) { console.error(err); alert(err.message || 'Network error'); }
  return false;
}
 
/* Gather cart form data for update */
function gatherCartFormData(){
  const fd = new FormData();
  document.querySelectorAll('.cart-qty').forEach(inp => {
    const pid = inp.getAttribute('data-pid');
    fd.append('qty['+pid+']', inp.value);
  });
  const disc = document.getElementById('discountAmount');
  if (disc) fd.append('discount_amount', disc.value || 0);
  return fd;
}
function debounce(fn, wait){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(this,args), wait); }; }
 
const doUpdateCart = debounce(function(){
  const fd = gatherCartFormData(); fd.append('ajax','update');
  postFormData('pos.php', fd).then(res => {
    if (res.ok) {
      document.getElementById('cartCard').innerHTML = res.html;
      bindCartInteractions();
      updateTotalsFromResponse(res);
    }
  }).catch(err => { console.error(err); alert(err.message || 'Network error'); });
}, 300);
 
async function removeItemAjax(pid){
  const fd = new FormData(); fd.append('ajax','remove'); fd.append('pid', pid);
  try {
    const res = await postFormData('pos.php', fd);
    if (res.ok) {
      document.getElementById('cartCard').innerHTML = res.html;
      bindCartInteractions();
      updateTotalsFromResponse(res);
    }
  } catch (err) { console.error(err); alert(err.message || 'Network error'); }
}
 
async function toggleVatAjax(){
  const fd = new FormData(); fd.append('ajax','toggle_vat');
  try {
    const res = await postFormData('pos.php', fd);
    if (res.ok) {
      const btn = document.getElementById('vatToggleBtn');
      btn.dataset.vat = res.vat_enabled ? '1' : '0';
      btn.innerText = 'VAT ' + (res.vat_enabled ? 'On' : 'Off');
      document.getElementById('cartCard').innerHTML = res.html;
      bindCartInteractions();
      updateTotalsFromResponse(res);
    }
  } catch (err) { console.error(err); alert(err.message || 'Network error'); }
}
 
async function refreshStocks(ids){
  if (!ids || !ids.length) return;
  const fd = new FormData();
  fd.append('ajax','stocks');
  ids.forEach(id => fd.append('ids[]', id));
  try {
    const res = await postFormData('pos.php', fd);
    if (res.ok && res.stocks) Object.entries(res.stocks).forEach(([pid, stk]) => setProductStockUI(pid, stk));
  } catch (err) { console.error(err); }
}
 
/* change line UoM via AJAX */
async function changeLineUomAjax(pid, uom){
  const fd = new FormData();
  fd.append('ajax','change_uom');
  fd.append('pid', pid);
  fd.append('uom', uom);
  try {
    const res = await postFormData('pos.php', fd);
    if (res.ok) {
      document.getElementById('cartCard').innerHTML = res.html;
      bindCartInteractions();
      updateTotalsFromResponse(res);
    } else {
      alert('Could not change UoM: ' + (res.error || 'unknown'));
    }
  } catch (err) { console.error(err); alert(err.message || 'Network error'); }
}
 
/* Pre-check: calls products.php?ajax=check_sell for each line before checkout */
async function precheckCartLines(cartItems) {
  const lines = [];
  if (Array.isArray(cartItems)) {
    for (const it of cartItems) lines.push(it);
  } else {
    for (const k in cartItems) {
      const it = cartItems[k];
      lines.push(it);
    }
  }
 
  for (const line of lines) {
    const pid = line.id || line.product_id || line[0];
    const qty = line.qty || 0;
    const uom = line.uom || '';
    if (!pid || !qty) {
      return { ok: false, error: 'Invalid cart line for product ' + (pid || '') };
    }
    const url = `products.php?ajax=check_sell&product_id=${encodeURIComponent(pid)}&qty=${encodeURIComponent(qty)}&uom=${encodeURIComponent(uom)}`;
    try {
      const resp = await fetch(url, { credentials: 'same-origin' });
      const txt = await resp.text();
      if (!resp.ok) {
        let parsed = null;
        try { parsed = JSON.parse(txt); } catch(e){}
        return { ok:false, error: (parsed && parsed.error) ? parsed.error : `Inventory check failed for product ${pid}` };
      }
      const data = JSON.parse(txt);
      if (!data.ok) {
        return { ok:false, error: `Cannot sell ${qty} ${uom || ''} of product ${line.name || pid}: ${data.error}` };
      }
    } catch (err) {
      return { ok:false, error: 'Network error during inventory check: ' + err.message };
    }
  }
  return { ok:true };
}
 
/* Checkout flow: pre-check then pos.php AJAX checkout */
async function checkoutAjax(amountGiven){
  const total = parseFloat(document.getElementById('totalVal')?.innerText || '0') || 0;
  if ((amountGiven || 0) < total) {
    alert('Amount is insufficient.\n\nTotal due: ' + moneyFmt(total) + '\nAmount given: ' + moneyFmt(amountGiven || 0));
    return;
  }
 
  // Reconstruct cart snapshot from DOM
  const cartItems = {};
  document.querySelectorAll('#cartCard [data-cart-row]').forEach(node => {
    const pid = node.getAttribute('data-cart-row');
    const qtyInput = node.querySelector('.cart-qty');
    const qty = qtyInput ? parseFloat(qtyInput.value || 0) : 0;
    const uom = node.querySelector('.line-uom') ? node.querySelector('.line-uom').value : node.getAttribute('data-uom') || '';
    const nameEl = node.querySelector('div[style*="font-weight:600"]');
    const name = nameEl ? nameEl.innerText : '';
    cartItems[pid] = { id: pid, qty: qty, uom: uom, name: name };
  });
 
  const pre = await precheckCartLines(cartItems);
  if (!pre.ok) { alert(pre.error || 'Inventory validation failed'); return; }
 
  const fd = new FormData(); fd.append('ajax','checkout'); fd.append('amount_given', amountGiven || 0);
  try {
    const res = await postFormData('pos.php', fd);
    if (res.ok) {
      // notify if inventory was skipped
      if (res.receipt && res.receipt.inventory_skipped) {
        alert('Sale recorded but inventory updates were skipped for this user. Admins should reconcile stock later.');
      }
      showPostCheckoutModal(res.receipt);
      const ids = Object.keys(res.receipt.items || {}).map(k => parseInt(k)).filter(v=>!isNaN(v));
      if (ids.length) refreshStocks(ids);
      const fd2 = new FormData(); fd2.append('ajax','update');
      const res2 = await postFormData('pos.php', fd2);
      if (res2.ok) {
        document.getElementById('cartCard').innerHTML = res2.html;
        bindCartInteractions();
        updateTotalsFromResponse(res2);
      }
    } else {
      const msg = res.error || 'Checkout failed';
      if (typeof res.total_due !== 'undefined') alert(msg + '\n\nTotal due: ' + moneyFmt(res.total_due) + '\nAmount given: ' + moneyFmt(res.amount_given || 0));
      else alert(msg);
    }
  } catch (err) {
    console.error(err); alert(err.message || 'Network error during checkout');
  }
}
 
/* UI helpers: receipt and binding */
function updateTotalsFromResponse(res){
  if (!res) return;
  const s = (id,v)=>{ const el=document.getElementById(id); if(el) el.innerText = Number(v).toFixed(2); };
  s('subtotalVal', res.subtotal);
  s('discountAmount', res.discount_amount);
  s('subtotalAfterDiscountVal', res.subtotal_after_discount);
  s('vatVal', res.vat);
  s('totalVal', res.total);
  recalcChange();
}
function recalcChange() {
  const total = parseFloat(document.getElementById('totalVal')?.innerText || '0') || 0;
  const given = parseFloat(document.getElementById('amountGiven')?.value || 0) || 0;
  const changeEl = document.getElementById('changeDisplay');
  if (changeEl) changeEl.innerText = moneyFmt(given - total);
}
 
function bindCartInteractions(){
  document.querySelectorAll('.cart-qty').forEach(inp => {
    const newInp = inp.cloneNode(true);
    inp.parentNode.replaceChild(newInp, inp);
    newInp.addEventListener('input', function(){ doUpdateCart(); });
    newInp.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); doUpdateCart(); } });
  });
 
  // UoM selects
  document.querySelectorAll('.line-uom').forEach(sel => {
    sel.onchange = function(){
      const pid = this.getAttribute('data-pid');
      const uom = this.value;
      changeLineUomAjax(pid, uom);
    };
  });
 
  document.querySelectorAll('.remove-item').forEach(btn => {
    btn.onclick = function(){ const pid = this.getAttribute('data-pid'); if (!confirm('Remove this item?')) return; removeItemAjax(pid); };
  });
 
  const disc = document.getElementById('discountAmount');
  if (disc) {
    const discClone = disc.cloneNode(true);
    disc.parentNode.replaceChild(discClone, disc);
    discClone.addEventListener('input', function(){ doUpdateCart(); });
    discClone.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); doUpdateCart(); } });
  }
 
  const amountGiven = document.getElementById('amountGiven');
  if (amountGiven) {
    const agClone = amountGiven.cloneNode(true);
    amountGiven.parentNode.replaceChild(agClone, amountGiven);
    agClone.addEventListener('input', recalcChange);
    agClone.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        const amt = parseFloat(agClone.value || 0);
        checkoutAjax(amt);
      }
    });
  }
  const checkoutBtn = document.getElementById('checkoutBtn');
  if (checkoutBtn) {
    checkoutBtn.onclick = function(){
      const amt = parseFloat(document.getElementById('amountGiven')?.value || 0);
      checkoutAjax(amt);
    };
  }
}
 
const doUpdateCartAjax = debounce(function(){
  const fd = gatherCartFormData(); fd.append('ajax','update');
  postFormData('pos.php', fd).then(res => {
    if (res.ok) {
      document.getElementById('cartCard').innerHTML = res.html;
      bindCartInteractions();
      updateTotalsFromResponse(res);
    }
  }).catch(err => { console.error(err); alert(err.message || 'Network error'); });
}, 300);
 
/* Instant search */
async function fetchProducts(q){
  const url = 'pos.php?ajax=products&q=' + encodeURIComponent(q || '');
  const resp = await fetch(url, { credentials: 'same-origin' });
  const text = await resp.text();
  if (!resp.ok) throw new Error(`HTTP ${resp.status}: ${text.slice(0,500)}`);
  let data;
  try { data = JSON.parse(text); } catch (e) { throw new Error(`Invalid JSON from server: ${text.slice(0,500)}`); }
  return data;
}
const doLiveSearch = debounce(async function(q){
  try{
    const res = await fetchProducts(q);
    if (res.ok) {
      const container = document.getElementById('productsContainer');
      container.innerHTML = res.html;
      bindProductUomListeners();
    }
  }catch(e){ console.error(e); }
}, 250);
 
/* Bind UoM change listeners for product cards */
function bindProductUomListeners() {
  document.querySelectorAll('.add-to-cart-form select[name="uom"]').forEach(sel => {
    sel.addEventListener('change', function() {
      const form = this.closest('form');
      const card = form.closest('.product-card');
      const priceEl = card.querySelector('.price');
      if (!priceEl) return;
      const basePrice = parseFloat(card.dataset.basePrice) || 0;
      const altPrice = parseFloat(card.dataset.altPrice) || 0;
      const altUom = card.dataset.altUom || '';
      const selectedUom = this.value;
      let displayPrice = basePrice;
      if (selectedUom === altUom && altPrice > 0) {
        displayPrice = altPrice;
      }
      priceEl.innerText = '₱ ' + displayPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    });
  });
}
 
/* Receipt & print (kept compact) */
function showPostCheckoutModal(r){
  const body = document.getElementById('postCheckoutBody');
  if (!body) return;
  let html = '<div class="text-center">';
  const logoExists = <?= file_exists(__DIR__.'/assets/logo.png') ? 'true' : 'false' ?>;
  if (logoExists) html += '<img src="assets/logo.png" style="height:48px;margin-bottom:8px"><br>';
  else html += '<div style="width:48px;height:48px;border-radius:6px;background:var(--brand);display:inline-flex;align-items:center;justify-content:center;color:white;font-weight:700">F</div>';
  html += '<div style="font-weight:700;font-size:1.05rem">Fusion I.T. Solutions Store</div>';
  html += '<div class="small-note">NOT AN OFFICIAL RECEIPT</div>';
  html += '</div><hr>';
  html += '<div style="font-size:.9rem">Seller: ' + escapeHtml(r.seller) + '<br>Time: ' + escapeHtml(r.timestamp) + '</div>';
  html += '<table class="table table-sm mt-2"><thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th></tr></thead><tbody>';
  for (const id in r.items) {
    const it = r.items[id];
    const uom = it.uom ? ' ' + escapeHtml(it.uom) : '';
    html += '<tr><td>' + escapeHtml(it.name) + (it.uom ? (' <small class="text-muted">('+escapeHtml(it.uom)+')</small>') : '') + '</td><td class="text-end">' + parseFloat(it.qty) + '</td><td class="text-end">₱ ' + (Number(it.price*it.qty).toFixed(2)) + '</td></tr>';
  }
  html += '</tbody></table>';
  html += '<div class="d-flex justify-content-between"><div>Subtotal</div><div>₱ ' + Number(r.subtotal).toFixed(2) + '</div></div>';
  html += '<div class="d-flex justify-content-between"><div>Discount</div><div>- ₱ ' + Number(r.discount_amount).toFixed(2) + '</div></div>';
  html += '<div class="d-flex justify-content-between"><div>VAT</div><div>₱ ' + Number(r.vat_amount).toFixed(2) + '</div></div>';
  html += '<div class="d-flex justify-content-between fw-bold mt-2"><div>Total</div><div>₱ ' + Number(r.total_due).toFixed(2) + '</div></div>';
  if (r.inventory_skipped) {
    html += '<hr><div class="text-danger small">Note: Inventory updates were skipped for this sale. ' + escapeHtml(r.inventory_note || '') + '</div>';
  }
  html += '</div>';
  body.innerHTML = html;
  const modalEl = document.getElementById('postCheckoutModal');
  const modal = new bootstrap.Modal(modalEl);
  modal.show();
  document.getElementById('printReceiptBtn').onclick = function(){ printReceiptHtml(body.innerHTML); };
  document.getElementById('proceedBtn').onclick = function(){ modal.hide(); };
}
 
function printReceiptHtml(htmlContent){
  const win = window.open('', '_blank', 'height=800,width=520');
  if (!win) { alert('Please allow popups to print the receipt'); return; }
  const paperClass = 'paper-80';
  win.document.write('<html><head><title>Receipt</title>');
  win.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">');
  win.document.write(`<style>body{font-family:monospace;}</style>`);
  win.document.write('</head><body>');
  win.document.write('<div class="receipt '+paperClass+'">');
  win.document.write(htmlContent);
  win.document.write('</div>');
  win.document.write('</body></html>');
  win.document.close();
  win.focus();
  setTimeout(()=> { win.print(); win.close(); }, 600);
}
 
/* DOM ready */
document.addEventListener('DOMContentLoaded', function(){
  bindCartInteractions();
  bindProductUomListeners();
 
  const vatBtn = document.getElementById('vatToggleBtn');
  if (vatBtn) vatBtn.addEventListener('click', function(){ toggleVatAjax(); });
 
  const searchInput = document.getElementById('searchInput');
  if (searchInput) {
    searchInput.addEventListener('input', function(){
      const q = this.value;
      doLiveSearch(q);
      const url = new URL(window.location);
      if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
      window.history.replaceState({}, '', url);
    });
  }
});
</script>
</body>
</html>
