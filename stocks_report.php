<?php
date_default_timezone_set('Asia/Manila');
require 'auth.php';
require_login();

/* ===== Roles allowed here ===== */
$user_role = $_SESSION['user']['role'] ?? '';
$user_role = strtolower(trim((string)$user_role));

/* If session stores multiple roles (array) prefer that */
if (!empty($_SESSION['user']['roles']) && is_array($_SESSION['user']['roles'])) {
    $user_roles = array_map(function($r){ return strtolower(trim((string)$r)); }, $_SESSION['user']['roles']);
} else {
    $user_roles = [];
}

/* Deny explicitly if cashier is present */
if (in_array('cashier', $user_roles, true) || $user_role === 'cashier') {
    http_response_code(403);
    exit('Forbidden');
}

/* Allowed roles (cashier intentionally omitted) */
$allowed_roles = ['admin','store_manager','inventory account','inventory_account','inventory'];
$has_allowed_role = count(array_intersect($user_roles, $allowed_roles)) > 0;

if (!$has_allowed_role) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = get_db();

/* ========= Utils ========= */
function db_has_table(PDO $pdo, $tbl){
    try { @$pdo->query("SELECT 1 FROM `$tbl` LIMIT 1"); return true; } catch(Exception $e){}
    try { $s = @$pdo->query("PRAGMA table_info($tbl)"); return (bool)$s; } catch(Exception $e){ return false; }
}
function table_cols(PDO $pdo, $tbl){
    $cols = [];
    try {
        $st = @$pdo->query("PRAGMA table_info($tbl)");
        if ($st) foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($r['name'])) $cols[] = strtolower($r['name']);
    } catch(Exception $e){}
    if (!$cols) {
        try {
            $st = @$pdo->query("DESCRIBE `$tbl`");
            if ($st) foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($r['Field'])) $cols[] = strtolower($r['Field']);
        } catch(Exception $e){}
    }
    return $cols;
}
function rows(PDO $pdo,$sql,$p=[]){
    try{ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }catch(Exception $e){ return []; }
}
function scalar(PDO $pdo,$sql,$p=[],$def=0){
    try{ $s=$pdo->prepare($sql); $s->execute($p); $v=$s->fetchColumn(); return is_null($v)?$def:(float)$v; }catch(Exception $e){ return $def; }
}
function money($x){ return number_format((float)$x,2); }
function h($x){ return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }

/* ========= Products schema probes ========= */
if (!db_has_table($pdo,'products')) { http_response_code(500); exit('Products table not found.'); }
$prod_cols = table_cols($pdo,'products');

$has_sku  = in_array('sku',$prod_cols,true)  || in_array('code',$prod_cols,true);
$has_name = in_array('name',$prod_cols,true) || in_array('title',$prod_cols,true);

$stock_col = null; foreach (['stock','qty','quantity','onhand','on_hand'] as $c) { if (in_array($c,$prod_cols,true)) { $stock_col=$c; break; } }
$price_col = null; foreach (['price','srp','unit_price'] as $c) { if (in_array($c,$prod_cols,true)) { $price_col=$c; break; } }
$cat_col   = null; foreach (['category','category_name','cat','product_category','group','dept','department'] as $c) { if (in_array($c,$prod_cols,true)) { $cat_col=$c; break; } }
$unit_col  = null; foreach (['unit','uom','measure','unit_name','unit_of_measure','measurement'] as $c) { if (in_array($c,$prod_cols,true)) { $unit_col=$c; break; } }

$sku_expr   = $has_sku   ? (in_array('sku',$prod_cols,true) ? 'p.sku' : 'p.code') : "''";
$name_expr  = $has_name  ? (in_array('name',$prod_cols,true) ? 'p.name' : 'p.title') : "''";
$stock_expr = $stock_col ? "CAST(p.`$stock_col` AS REAL)" : "0";
$price_expr = $price_col ? "CAST(p.`$price_col` AS REAL)" : "0";
$cat_expr   = $cat_col    ? "p.`$cat_col`" : "NULL";
$unit_expr  = $unit_col   ? "p.`$unit_col`" : "NULL";

/* ========= Filters & defaults ========= */
$tab = $_GET['tab'] ?? 'all';
$search   = trim($_GET['q'] ?? '');
$f_cat  = isset($_GET['category']) ? trim($_GET['category']) : '';
$f_unit = isset($_GET['unit'])     ? trim($_GET['unit'])     : '';
$critical_limit = 10;

/* Sorting */
$sort = $_GET['sort'] ?? 'stock';
$dir  = $_GET['dir']  ?? 'asc';
$sort_cols = ['id','sku','name','stock','price','category','unit'];
if (!in_array($sort, $sort_cols)) $sort = 'stock';
$dir = in_array($dir, ['asc','desc']) ? $dir : 'asc';
$toggle_dir = $dir === 'asc' ? 'desc' : 'asc';

/* Pagination */
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset  = ($page - 1) * $per_page;

/* ========= Distinct lists for filters ========= */
$cat_list  = $cat_col ? rows($pdo, "SELECT DISTINCT $cat_expr AS v FROM products p WHERE $cat_expr IS NOT NULL AND TRIM($cat_expr)<>'' ORDER BY v", []) : [];
$unit_list = $unit_col ? rows($pdo, "SELECT DISTINCT $unit_expr AS v FROM products p WHERE $unit_expr IS NOT NULL AND TRIM($unit_expr)<>'' ORDER BY v", []) : [];

/* ========= Build WHERE clause ========= */
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(' . $sku_expr . ' LIKE ? OR ' . $name_expr . ' LIKE ?)';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($cat_col && $f_cat !== '') { $where[] = "$cat_expr = ?"; $params[] = $f_cat; }
if ($unit_col && $f_unit !== ''){ $where[] = "$unit_expr = ?"; $params[] = $f_unit; }

if ($tab === 'critical') { $where[] = "($stock_expr <= ?)"; $params[] = $critical_limit; }
if ($tab === 'zero')     { $where[] = "($stock_expr <= 0)"; }

/* Count total for pagination */
$count_sql = "SELECT COUNT(*) FROM products p WHERE " . implode(' AND ', $where);
$total_items = (int)scalar($pdo, $count_sql, $params, 0);
$total_pages = max(1, ceil($total_items / $per_page));

/* Main list query */
$order_by = match($sort) {
    'sku'      => $sku_expr,
    'name'     => $name_expr,
    'stock'    => $stock_expr,
    'price'    => $price_expr,
    'category' => $cat_expr,
    'unit'     => $unit_expr,
    default    => 'p.id'
};

$list_sql = "
    SELECT
        p.id,
        $sku_expr   AS sku,
        $name_expr  AS name,
        $stock_expr AS stock,
        $price_expr AS price,
        $cat_expr   AS category,
        $unit_expr  AS unit
    FROM products p
    WHERE ".implode(' AND ', $where)."
    ORDER BY $order_by $dir, $sku_expr ASC, $name_expr ASC
    LIMIT $per_page OFFSET $offset
";
$items = rows($pdo, $list_sql, $params);

/* Last updated (if column exists) */
$last_updated = '';
if (in_array('updated_at', $prod_cols) || in_array('updated_at', array_map('strtolower', $prod_cols))) {
    $last_updated = scalar($pdo, "SELECT MAX(updated_at) FROM products", [], '');
    if ($last_updated) {
        $last_updated = date('M d, Y h:i A', strtotime($last_updated));
    }
}

/* KPIs */
$total_skus      = (int)scalar($pdo, "SELECT COUNT(*) FROM products", [], 0);
$zero_stock      = (int)scalar($pdo, "SELECT COUNT(*) FROM products p WHERE $stock_expr <= 0", [], 0);
$critical_stock  = (int)scalar($pdo, "SELECT COUNT(*) FROM products p WHERE $stock_expr <= ?", [$critical_limit], 0);

/* ========= Export CSV ========= */
if (isset($_GET['export']) && $_GET['export']==='csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stocks_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    $hdr = ['ID','SKU','Name'];
    if ($cat_col) $hdr[]='Category';
    if ($unit_col) $hdr[]='Unit';
    $hdr[] = 'Stock';
    $hdr[] = 'Price';
    $hdr[] = 'Status';
    fputcsv($out, $hdr);

    foreach ($items as $row) {
        $stk = (float)$row['stock'];
        $status = ($stk <= 0) ? 'Zero' : (($stk <= $critical_limit) ? 'Critical' : 'OK');
        $line = [
            $row['id'],
            $row['sku'],
            $row['name'],
        ];
        if ($cat_col) $line[] = $row['category'];
        if ($unit_col) $line[] = $row['unit'];
        $line[] = $stk;
        $line[] = isset($row['price']) ? number_format((float)$row['price'],2,'.','') : '';
        $line[] = $status;
        fputcsv($out, $line);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Stocks Report • Fusion I.T. Solutions</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{--brand:#fc3503;--muted:#6c757d}
    *{box-sizing:border-box}
    body{background:#f6f7fb;color:#222;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
    .topbar{background:linear-gradient(90deg,#ffffff 0%,#f8f9ff 100%);padding:12px;border-radius:10px;box-shadow:0 2px 6px rgba(16,24,40,0.04);margin-bottom:1rem}
    .brand{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
    .brand img{height:44px;width:auto;max-width:100%;object-fit:contain}
    .brand-text{min-width:0}
    .brand-title{font-size:1.15rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .subtitle{font-size:.9rem;color:var(--muted)}
    .topbar-actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;justify-content:flex-end}

    .btn,
    .btn-primary,.btn-secondary,.btn-success,.btn-danger,.btn-warning,.btn-info,.btn-dark,.btn-light{
      background-color:var(--brand) !important;border-color:var(--brand) !important;color:#fff !important;white-space:nowrap;
    }
    .btn:hover,.btn:focus,.btn:active{background-color:var(--brand) !important;border-color:var(--brand) !important;color:#fff !important;opacity:0.9}
    .btn-outline-primary,.btn-outline-secondary,.btn-outline-success,.btn-outline-danger,.btn-outline-warning,.btn-outline-info,.btn-outline-dark,.btn-outline-light{
      background-color:transparent !important;border-color:var(--brand) !important;color:var(--brand) !important;
    }
    .btn-outline-primary:hover,.btn-outline-secondary:hover,.btn-outline-success:hover,.btn-outline-danger:hover,.btn-outline-warning:hover,.btn-outline-info:hover,.btn-outline-dark:hover,.btn-outline-light:hover{
      background-color:var(--brand) !important;color:#fff !important;
    }

    .card-quiet{background:transparent;border:0}
    .kpi{border:0;border-radius:14px;box-shadow:0 4px 18px rgba(16,24,40,.06);background:#fff;height:100%}
    .kpi .label{color:#6b7280;font-size:.85rem}
    .kpi .value{font-size:1.35rem;font-weight:800;word-break:break-word}
    .kpi .hint{font-size:.8rem;color:#9ca3af}
    .kpi .icon{font-size:1.25rem}
    .status-badge{font-size:.75rem;padding:.2rem .5rem;border-radius:24px;display:inline-block;white-space:nowrap}
    .status-zero{background:#ffe2e2;color:#b42318}
    .status-critical{background:#fff3cd;color:#ad6800}
    .status-ok{background:#e6ffed;color:#057647}
    .table{width:100%;margin-bottom:0}
    .table thead th{white-space:nowrap;vertical-align:middle}
    .table tbody td{word-break:break-word}
    .number{white-space:nowrap}
    mark{background:#fff3cd;padding:1px 4px;border-radius:3px}
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}

    @media print{
      .topbar-actions,.filters,.no-print{display:none !important}
      body{background:#fff}
      .kpi{box-shadow:none;border:1px solid #ddd}
    }

    @media (max-width:767.98px){
      .topbar{flex-direction:column;align-items:stretch}
      .brand{justify-content:center;margin-bottom:.5rem}
      .topbar-actions{justify-content:center;width:100%}
      .btn-sm{font-size:.75rem;padding:.25rem .5rem}
      .kpi .value{font-size:1.15rem}
      .kpi .label{font-size:.8rem}
      .kpi .hint{font-size:.75rem}
      .brand-title{font-size:1rem}
      .subtitle{font-size:.85rem}
      .table{font-size:.85rem}
      .table thead th{font-size:.8rem;padding:.5rem .25rem}
      .table tbody td{padding:.5rem .25rem}
      .pagination{font-size:.85rem}
      .card{padding:0 !important}
      .filters .col-12{margin-bottom:.5rem}
      .filters .form-label{font-size:.8rem}
    }

    @media (max-width:575.98px){
      .container{padding-left:.5rem;padding-right:.5rem}
      .topbar-actions{flex-direction:column;width:100%}
      .topbar-actions .btn{width:100%}
      .kpi{margin-bottom:.5rem}
      .table{font-size:.8rem}
      .table thead th{font-size:.75rem;padding:.4rem .2rem}
      .table tbody td{padding:.4rem .2rem}
      .status-badge{font-size:.7rem;padding:.15rem .35rem}
      .brand img,.brand>div:first-child{height:36px;width:36px;font-size:.9rem}
      .brand-title{font-size:.95rem}
      .pagination .page-link{padding:.375rem .65rem;font-size:.8rem}
    }

    @media (min-width:768px){
      .topbar{flex-direction:row;align-items:center}
    }

    @supports (-webkit-touch-callout:none){
      .table-responsive{-webkit-overflow-scrolling:touch}
    }
  </style>
</head>
<body>
<div class="container py-4">

  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center topbar">
    <div class="brand">
      <?php if (file_exists(__DIR__.'/assets/logo.png')): ?>
        <img src="assets/logo.png" alt="logo">
      <?php else: ?>
        <div style="width:44px;height:44px;border-radius:8px;background:var(--brand);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0">F</div>
      <?php endif; ?>
      <div class="brand-text">
        <div class="brand-title">Fusion I.T. Solutions</div>
        <div class="subtitle">Stocks Report</div>
      </div>
    </div>
    <div class="topbar-actions no-print">
      <a class="btn btn-outline-secondary btn-sm" href="index.php">Dashboard</a>
      <a class="btn btn-outline-secondary btn-sm" href="?export=csv&tab=<?=urlencode($tab)?>&q=<?=urlencode($search)?>&category=<?=urlencode($f_cat)?>&unit=<?=urlencode($f_unit)?>">Export CSV</a>
      <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>
      <a class="btn btn-outline-secondary btn-sm" href="logout.php">Logout</a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-2 mb-3">
    <div class="col-12 col-md-4">
      <div class="kpi p-3">
        <div class="d-flex justify-content-between"><span class="label">Total SKUs</span><span class="icon">📦</span></div>
        <div class="value"><?= number_format($total_skus) ?></div>
        <div class="hint">Products in catalog</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="kpi p-3">
        <div class="d-flex justify-content-between"><span class="label">Critical (≤ 10)</span><span class="icon">⚠️</span></div>
        <div class="value"><?= number_format($critical_stock) ?></div>
        <div class="hint">Low stock items</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="kpi p-3">
        <div class="d-flex justify-content-between"><span class="label">Zero Stock</span><span class="icon">⛔</span></div>
        <div class="value"><?= number_format($zero_stock) ?></div>
        <div class="hint">Out of stock</div>
      </div>
    </div>
  </div>

  <?php if ($last_updated): ?>
  <div class="text-end text-muted small mb-2">
    Last updated: <?= h($last_updated) ?>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="card card-quiet p-3 mb-3 filters no-print">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-12 col-sm-3">
        <label class="form-label small mb-0">View</label>
        <select name="tab" class="form-select form-select-sm">
          <option value="all"     <?= $tab==='all'?'selected':'' ?>>All Products</option>
          <option value="critical"<?= $tab==='critical'?'selected':'' ?>>Critical (≤ 10)</option>
          <option value="zero"    <?= $tab==='zero'?'selected':'' ?>>Zero Stock</option>
        </select>
      </div>

      <div class="col-12 col-sm-3">
        <label class="form-label small mb-0">Product</label>
        <input type="text" class="form-control form-control-sm" name="q" placeholder="Search SKU or Name" value="<?= h($search) ?>">
      </div>

      <?php if ($cat_col): ?>
      <div class="col-6 col-sm-3">
        <label class="form-label small mb-0"><?= h(ucwords(str_replace('_',' ',$cat_col))) ?></label>
        <select name="category" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($cat_list as $c): $cv=$c['v']; ?>
            <option value="<?= h($cv) ?>" <?= ($f_cat!=='' && $f_cat===$cv)?'selected':'' ?>><?= h($cv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($unit_col): ?>
      <div class="col-6 col-sm-2">
        <label class="form-label small mb-0"><?= h(ucwords(str_replace('_',' ',$unit_col))) ?></label>
        <select name="unit" class="form-select form-select-sm">
          <option value="">All Units</option>
          <?php foreach ($unit_list as $u): $uv=$u['v']; ?>
            <option value="<?= h($uv) ?>" <?= ($f_unit!=='' && $f_unit===$uv)?'selected':'' ?>><?= h($uv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="col-6 col-sm-1 d-grid">
        <button class="btn btn-primary btn-sm">Apply</button>
      </div>
    </form>
  </div>

  <!-- Results count -->
  <div class="mb-2">
    <strong><?= number_format($total_items) ?></strong> item<?= $total_items === 1 ? '' : 's' ?> found
    <?php if ($search || $f_cat || $f_unit || $tab !== 'all'): ?>
      <small class="text-muted">(filtered)</small>
    <?php endif; ?>
  </div>

  <!-- Table -->
  <div class="card p-2">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <?php
            $sort_url = function($col) use ($sort, $dir, $toggle_dir) {
                $q = $_GET;
                $q['sort'] = $col;
                $q['dir']  = ($sort === $col) ? $toggle_dir : 'asc';
                return '?' . http_build_query($q);
            };
            $sort_icon = function($col) use ($sort, $dir) {
                if ($sort !== $col) return '';
                return $dir === 'asc' ? ' ↑' : ' ↓';
            };
            ?>
            <th><a href="<?= $sort_url('id') ?>" class="text-dark text-decoration-none">ID<?= $sort_icon('id') ?></a></th>
            <th><a href="<?= $sort_url('sku') ?>" class="text-dark text-decoration-none">SKU<?= $sort_icon('sku') ?></a></th>
            <th><a href="<?= $sort_url('name') ?>" class="text-dark text-decoration-none">Name<?= $sort_icon('name') ?></a></th>
            <?php if ($cat_col): ?>
              <th><a href="<?= $sort_url('category') ?>" class="text-dark text-decoration-none">Category<?= $sort_icon('category') ?></a></th>
            <?php endif; ?>
            <?php if ($unit_col): ?>
              <th><a href="<?= $sort_url('unit') ?>" class="text-dark text-decoration-none">Unit<?= $sort_icon('unit') ?></a></th>
            <?php endif; ?>
            <th class="text-end">
              <a href="<?= $sort_url('stock') ?>" class="text-dark text-decoration-none">Stock<?= $sort_icon('stock') ?></a>
            </th>
            <th class="text-center">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="<?= 4 + (int)!!$cat_col + (int)!!$unit_col ?>" class="text-center text-muted py-4">
              No products found matching your criteria.
            </td>
          </tr>
        <?php else: foreach ($items as $row):
            $stk = (float)$row['stock'];
            $is_zero = $stk <= 0;
            $is_critical = $stk <= $critical_limit && !$is_zero;

            // Highlight search term
            $highlight = function($txt) use ($search) {
                if (!$search) return h($txt);
                return preg_replace(
                    '/(' . preg_quote($search, '/') . ')/i',
                    '<mark>$1</mark>',
                    h($txt)
                );
            };
        ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><?= $highlight($row['sku']) ?></td>
            <td><?= $highlight($row['name']) ?></td>
            <?php if ($cat_col): ?><td><?= h($row['category']) ?></td><?php endif; ?>
            <?php if ($unit_col): ?><td><?= h($row['unit']) ?></td><?php endif; ?>
            <td class="text-end number fw-bold"><?= rtrim(rtrim(number_format($stk, 2), '0'), '.') ?></td>
            <td class="text-center">
              <?php if ($is_zero): ?>
                <span class="status-badge status-zero">Zero</span>
              <?php elseif ($is_critical): ?>
                <span class="status-badge status-critical">Critical</span>
              <?php else: ?>
                <span class="status-badge status-ok">OK</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Page navigation" class="mt-4 no-print">
      <ul class="pagination pagination-sm justify-content-center">
        <?php
        $pq = $_GET;
        unset($pq['page']);
        $base_url = '?' . http_build_query($pq) . '&page=';

        $prev_disabled = $page <= 1 ? ' disabled' : '';
        $next_disabled = $page >= $total_pages ? ' disabled' : '';
        ?>
        <li class="page-item<?= $prev_disabled ?>">
          <a class="page-link" href="<?= $page > 1 ? $base_url . ($page-1) : '#' ?>" <?= $prev_disabled?'tabindex="-1" aria-disabled="true"':'' ?>>Previous</a>
        </li>

        <?php
        $delta = 2;
        $range_start = max(1, $page - $delta);
        $range_end   = min($total_pages, $page + $delta);

        if ($range_start > 1): ?>
          <li class="page-item"><a class="page-link" href="<?= $base_url ?>1">1</a></li>
          <?php if ($range_start > 2): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $range_start; $p <= $range_end; ++$p): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base_url . $p ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>

        <?php if ($range_end < $total_pages): ?>
          <?php if ($range_end < $total_pages - 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
          <li class="page-item"><a class="page-link" href="<?= $base_url . $total_pages ?>"><?= $total_pages ?></a></li>
        <?php endif; ?>

        <li class="page-item<?= $next_disabled ?>">
          <a class="page-link" href="<?= $page < $total_pages ? $base_url . ($page+1) : '#' ?>" <?= $next_disabled?'tabindex="-1" aria-disabled="true"':'' ?>>Next</a>
        </li>
      </ul>
    </nav>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
