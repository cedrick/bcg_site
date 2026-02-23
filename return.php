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
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Returns - Fusion I.T. Solutions</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>

<!-- Close Confirmation Modal -->
<div class="modal fade" id="closeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-footer">
        <form method="post" id="closeForm">
          <input type="hidden" name="close_return" value="1">
          <input type="hidden" name="id" id="closeId">
          <input type="hidden" name="add_to_inventory" id="addToInventory" value="no">
          <button type="button" class="btn btn-sm" id="addYesBtn" style="display:none;background-color:#198754!important;border-color:#198754!important;color:#fff!important;">Add to Inventory and Close</button>
          <button type="submit" class="btn btn-primary btn-sm" id="confirmClose">Close</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
$('#closeModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget);
    var id = button.data('id');
    var action = button.data('action');

    $('#closeId').val(id);
    $('#addToInventory').val('no');

    if (action === 'damage' || action === 'replace') {
        $('#addYesBtn').show();
    } else {
        $('#addYesBtn').hide();
    }
});

$(document).on('click', '#addYesBtn', function(e) {
    e.preventDefault();
    $('#addToInventory').val('yes');
    $('#closeForm').submit();
});
</script>

</body>
</html>
