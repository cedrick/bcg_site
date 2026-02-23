<?php
// return.php — Defective Item Returns & Replacement Log (Fusion POS)
date_default_timezone_set('Asia/Manila');
require 'auth.php';
require_login();
$db = get_db();
$current_user = $_SESSION['user']['username'] ?? 'system';

/* ================= Access control ================= */
$session_user = $_SESSION['user'] ?? [];
$role_from_string = isset($session_user['role']) ? strtolower(trim((string)$session_user['role'])) : '';
$roles_array = [];
if (!empty($session_user['roles']) && is_array($session_user['roles'])) {
    $roles_array = array_map('strtolower', $session_user['roles']);
} elseif ($role_from_string !== '') {
    $roles_array = [$role_from_string];
}
$caps = !empty($session_user['caps']) && is_array($session_user['caps'])
    ? array_change_key_case($session_user['caps'], CASE_LOWER) : [];

$allowed_roles = ['admin', 'store_manager'];
$denied_roles = ['cashier'];
$cap_allow_keys = ['manage_inventory', 'manage_products', 'access_inventory'];

$has_role_allow = in_array($role_from_string, $allowed_roles, true)
    || count(array_intersect($roles_array, $allowed_roles)) > 0;
$has_cap_allow = false;
foreach ($cap_allow_keys as $k) {
    if (!empty($caps[$k])) { $has_cap_allow = true; break; }
}
$has_denied_role = count(array_intersect($roles_array, $denied_roles)) > 0;
$is_allowed = ($has_role_allow || $has_cap_allow);
if ($has_denied_role && !$has_role_allow) $is_allowed = false;
if (!$is_allowed) { http_response_code(403); echo 'Forbidden'; exit; }

/* ================= Schema ================= */
$db->exec("CREATE TABLE IF NOT EXISTS returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL, sku TEXT, product_name TEXT,
    qty REAL NOT NULL, uom TEXT, reason TEXT,
    action TEXT DEFAULT 'replace', reference_no TEXT,
    status TEXT DEFAULT 'pending', created_by TEXT, created_at TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS stock_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER, old_stock REAL, new_stock REAL,
    qty_changed REAL, action_by TEXT, note TEXT, created_at TEXT
);");

/* ================= Utility ================= */
function compute_base_qty(array $product, float $qty, string $uom) : float {
    $base = $product['uom'] ?? '';
    $alt = $product['alt_uom'] ?? null;
    $factor = floatval($product['alt_factor'] ?? 1) ?: 1.0;
    if ($uom === $base || empty($alt)) return $qty;
    if ($uom === $alt) return $qty * $factor;
    return $qty;
}

/* ================= Handle POST: add_return ================= */
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_return'])) {
    $product_id = intval($_POST['product_id'] ?? 0);
    $qty = floatval($_POST['qty'] ?? 0);
    $uom = trim($_POST['uom'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $action = trim($_POST['action'] ?? 'replace');
    $reference_no = trim($_POST['reference_no'] ?? '');
    $created_at = date('Y-m-d H:i:s');
    if ($product_id <= 0 || $qty <= 0) {
        $msg = 'Invalid product or quantity.';
    } else {
        $stmt = $db->prepare("SELECT sku, name FROM products WHERE id=? LIMIT 1");
        $stmt->execute([$product_id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $db->prepare("INSERT INTO returns (product_id,sku,product_name,qty,uom,reason,action,reference_no,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$product_id,$p['sku'],$p['name'],$qty,$uom,$reason,$action,$reference_no,$current_user,$created_at]);
            $msg = 'Return record saved.';
        }
    }
}

/* ================= Handle POST: close_return ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_return'])) {
    $id = intval($_POST['id'] ?? 0);
    $add_to_inventory = isset($_POST['add_to_inventory']) && $_POST['add_to_inventory'] === 'yes';
    if ($id > 0) {
        $stmt = $db->prepare("SELECT * FROM returns WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($return) {
            $db->prepare("UPDATE returns SET status='closed' WHERE id=?")->execute([$id]);
            if (in_array($return['action'], ['damage', 'replace']) && $add_to_inventory) {
                $prod_stmt = $db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
                $prod_stmt->execute([$return['product_id']]);
                $product = $prod_stmt->fetch(PDO::FETCH_ASSOC);
                if ($product) {
                    $db->beginTransaction();
                    try {
                        $base_qty = compute_base_qty($product, $return['qty'], $return['uom']);
                        $old_stock = floatval($product['stock'] ?: 0);
                        $new_stock = $old_stock + $base_qty;
                        $db->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$new_stock, $return['product_id']]);
                        $note = 'Added from return #' . $id . ' (' . $return['action'] . ')';
                        if ($return['uom'] !== $product['uom']) {
                            $note .= ' (converted ' . $return['qty'] . ' ' . $return['uom'] . ' to ' . $base_qty . ' ' . $product['uom'] . ')';
                        }
                        $db->prepare("INSERT INTO stock_logs (product_id,old_stock,new_stock,qty_changed,action_by,note,created_at) VALUES (?,?,?,?,?,?,?)")
                           ->execute([$return['product_id'],$old_stock,$new_stock,$base_qty,$current_user,$note,date('Y-m-d H:i:s')]);
                        $db->commit();
                        $msg = 'Return marked as resolved and added to inventory (base qty: ' . $base_qty . ').';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $msg = 'Error updating inventory: ' . $e->getMessage();
                    }
                } else {
                    $msg = 'Return marked as resolved, but product not found for inventory update.';
                }
            } else {
                $msg = 'Return marked as resolved (not added to inventory).';
            }
        } else { $msg = 'Return not found.'; }
    } else { $msg = 'Invalid return ID.'; }
}

/* ================= AJAX ================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'returns') {
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT * FROM returns";
    $params = [];
    if (strlen($q) > 0) {
        $sql .= " WHERE (reference_no LIKE :q OR product_name LIKE :q OR sku LIKE :q OR reason LIKE :q OR action LIKE :q OR status LIKE :q OR created_by LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ob_start();
    if (empty($returns)) {
        echo '<tr><td colspan="9" class="text-muted">No returns logged.</td></tr>';
    } else {
        foreach ($returns as $r) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['created_at']) . '</td>';
            echo '<td>' . htmlspecialchars($r['sku'] . ' - ' . $r['product_name']) . '</td>';
            echo '<td>' . htmlspecialchars($r['qty'] . ' ' . $r['uom']) . '</td>';
            echo '<td>' . htmlspecialchars($r['reason']) . '</td>';
            echo '<td>' . htmlspecialchars($r['reference_no']) . '</td>';
            echo '<td>' . htmlspecialchars($r['action']) . '</td>';
            echo '<td><span class="badge-status status-' . htmlspecialchars($r['status']) . '">' . htmlspecialchars($r['status']) . '</span></td>';
            echo '<td>' . htmlspecialchars($r['created_by']) . '</td>';
            echo '<td>';
            if ($r['status'] !== 'closed') {
                echo '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#closeModal" data-id="' . intval($r['id']) . '" data-action="' . htmlspecialchars($r['action']) . '">Close</button>';
            }
            echo '</td></tr>';
        }
    }
    $html = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['html' => $html, 'count' => count($returns)]);
    exit;
}

/* ================= Data ================= */
$products = $db->query("SELECT id, sku, name FROM products ORDER BY name COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
$uoms = $db->query("SELECT name FROM uoms ORDER BY name COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC);
$returns = $db->query("SELECT * FROM returns ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Returns - Fusion I.T. Solutions</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
:root{--brand:#fc3503;--muted:#6c757d}
body{background:#f6f7fb;color:#222}
.topbar{background:linear-gradient(90deg,#fff,#f8f9ff);padding:12px;border-radius:10px;box-shadow:0 2px 6px rgba(16,24,40,.04)}
.brand{display:flex;align-items:center;gap:.75rem}
.brand img{height:44px;object-fit:contain}
.badge-status{padding:.35rem .6rem;border-radius:.5rem;font-weight:600}
.status-pending{background:#fff3cd;color:#664d03}
.status-closed{background:#e7f1ff;color:#0d6efd}
.btn,.btn-primary{background-color:var(--brand)!important;border-color:var(--brand)!important;color:#fff!important}
.btn-outline-primary{background:transparent!important;border-color:var(--brand)!important;color:var(--brand)!important}
</style>
</head>
<body>
<div class="container py-4">

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-center mb-3 topbar">
  <div class="brand">
    <?php if (file_exists(__DIR__.'/assets/logo.png')): ?>
      <img src="assets/logo.png" alt="logo">
    <?php else: ?>
      <div style="width:44px;height:44px;border-radius:8px;background:var(--brand);display:flex;align-items:center;justify-content:center;color:white;font-weight:700">F</div>
    <?php endif; ?>
    <div>
      <div style="font-size:1.15rem;font-weight:700">Fusion I.T. Solutions</div>
      <div class="small text-muted">Defective Items & Returns Log</div>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <input id="globalSearch" class="form-control form-control-sm" placeholder="Search Sale ID, product, reason, user...">
    <a href="products.php" class="btn btn-outline-primary btn-sm">Products</a>
    <a href="index.php" class="btn btn-outline-primary btn-sm">Dashboard</a>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Add Return Form -->
<div class="card mb-3">
<div class="card-body">
<h6 class="mb-3">Log Defective Item</h6>
<form method="post" class="row g-2">
<div class="col-md-4">
  <label class="form-label small">Product</label>
  <select name="product_id" class="form-select form-select-sm product-picker" required>
    <option value="">-- select product --</option>
    <?php foreach ($products as $p): ?>
    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['sku'].' - '.$p['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="col-md-2">
  <label class="form-label small">Qty</label>
  <input name="qty" type="number" step="any" class="form-control form-control-sm" required>
</div>
<div class="col-md-2">
  <label class="form-label small">UoM</label>
  <select name="uom" class="form-select form-select-sm">
    <option value="">-- UoM --</option>
    <?php foreach ($uoms as $u): ?>
    <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="col-md-4">
  <label class="form-label small">Reason</label>
  <input name="reason" class="form-control form-control-sm" placeholder="Defective / damaged">
</div>
<div class="col-md-3">
  <label class="form-label small">Action</label>
  <select name="action" class="form-select form-select-sm">
    <option value="damage">Damage</option>
    <option value="replace">Replace</option>
  </select>
</div>
<div class="col-md-3">
  <label class="form-label small">Sales ID</label>
  <input name="reference_no" class="form-control form-control-sm">
</div>
<div class="col-12 text-end">
  <button name="add_return" class="btn btn-primary btn-sm">Save Return</button>
</div>
</form>
</div>
</div>

<!-- Return History -->
<div class="card">
<div class="card-body">
<h6 class="mb-3">Return History</h6>
<div class="table-responsive">
<table class="table table-sm table-hover">
<thead class="table-light">
<tr>
<th>Date</th><th>Product</th><th>Qty</th><th>Reason</th><th>Sale ID</th>
<th>Action</th><th>Status</th><th>User</th><th></th>
</tr>
</thead>
<tbody id="returnsTbody">
<?php if (!$returns): ?>
<tr><td colspan="9" class="text-muted">No returns logged.</td></tr>
<?php else: foreach ($returns as $r): ?>
<tr>
<td><?= htmlspecialchars($r['created_at']) ?></td>
<td><?= htmlspecialchars($r['sku'].' - '.$r['product_name']) ?></td>
<td><?= htmlspecialchars($r['qty'].' '.$r['uom']) ?></td>
<td><?= htmlspecialchars($r['reason']) ?></td>
<td><?= htmlspecialchars($r['reference_no']) ?></td>
<td><?= htmlspecialchars($r['action']) ?></td>
<td><span class="badge-status status-<?= htmlspecialchars($r['status']) ?>">
<?= htmlspecialchars($r['status']) ?></span></td>
<td><?= htmlspecialchars($r['created_by']) ?></td>
<td>
<?php if ($r['status'] !== 'closed'): ?>
<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#closeModal"
    data-id="<?= intval($r['id']) ?>" data-action="<?= htmlspecialchars($r['action']) ?>">
    Close
</button>
<?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- Close Confirmation Modal -->
<div class="modal fade" id="closeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Close Return</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Mark this return as resolved?</p>
        <div id="inventory-prompt" style="display:none;">
          <p>Do you want to add this item back to inventory?</p>
        </div>
      </div>
      <div class="modal-footer" id="closeModalFooter">
        <form method="post" id="closeForm" class="d-flex gap-2 w-100 justify-content-end">
          <input type="hidden" name="close_return" value="1">
          <input type="hidden" name="id" id="closeId">
          <input type="hidden" name="add_to_inventory" id="addToInventory" value="no">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="close_return" class="btn btn-primary btn-sm" id="confirmClose">Close</button>
        </form>
      </div>
    </div>
  </div>
</div>

<footer class="text-muted small mt-3">
Returns are logged for accountability. Inventory may be optionally updated on close for damage/replace (with UoM conversion).
</footer>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {

    // Initialize searchable product picker
    $('.product-picker').select2({
        placeholder: "-- search product --",
        allowClear: true
    });

    // ========== Global search — automatic, no button ==========
    var searchTimer = null;
    $('#globalSearch').on('input', function() {
        var q = $(this).val();
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            $.ajax({
                url: '?ajax=returns',
                data: { q: q },
                dataType: 'json',
                success: function(data) {
                    if (data && typeof data.html !== 'undefined') {
                        $('#returnsTbody').html(data.html);
                    }
                },
                error: function() {
                    console.error('Search failed');
                }
            });
        }, 300);
    });

    // ========== Modal logic for close button ==========
    $('#closeModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var id = button.data('id');
        var action = button.data('action');

        var modal = $(this);
        modal.find('#closeId').val(id);
        modal.find('#addToInventory').val('no');

        var prompt = modal.find('#inventory-prompt');
        var confirmBtn = modal.find('#confirmClose');

        // Remove any existing "Add to Inventory" button to avoid duplicates
        modal.find('#addYesBtn').remove();

        if (action === 'damage' || action === 'replace') {
            prompt.show();
            confirmBtn.text('Close Without Adding');
            // Insert "Add to Inventory and Close" button before the close button
            $('<button type="button" id="addYesBtn" class="btn btn-sm" style="background-color:#198754!important;border-color:#198754!important;color:#fff!important;">Add to Inventory and Close</button>')
                .insertBefore(confirmBtn);
        } else {
            prompt.hide();
            confirmBtn.text('Close');
        }
    });

    // Reset modal when hidden
    $('#closeModal').on('hidden.bs.modal', function () {
        $(this).find('#addYesBtn').remove();
        $(this).find('#inventory-prompt').hide();
        $(this).find('#addToInventory').val('no');
        $(this).find('#confirmClose').text('Close');
    });

    // Handle "Add to Inventory and Close" button click
    $(document).on('click', '#addYesBtn', function(e) {
        e.preventDefault();
        $('#addToInventory').val('yes');
        $('#closeForm').submit();
    });

});
</script>
</body>
</html>
