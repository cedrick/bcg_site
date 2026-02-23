<?php
date_default_timezone_set('Asia/Manila');
require 'auth.php';
require_login();

/* ===== Roles allowed here ===== */
$role = $_SESSION['user']['role'] ?? '';
$role = strtolower(trim((string)$role));

/* If session stores multiple roles (array) prefer that */
if (!empty($_SESSION['user']['roles']) && is_array($_SESSION['user']['roles'])) {
    $roles = array_map(function($r){ return strtolower(trim((string)$r)); }, $_SESSION['user']['roles']);
} else {
    $roles = [];
}

/* Deny explicitly if cashier is present */
if (in_array('cashier', $roles, true) || $role === 'cashier') {
    http_response_code(403);
    exit('Forbidden');
}

/* Allowed roles (cashier intentionally omitted) */
$allowed = ['admin','store_manager','inventory account','inventory_account','inventory'];
$authorized = count(array_intersect($roles, $allowed)) > 0;

if (!$authorized) {
    http_response_code(403);
    exit('Forbidden');
}

$db = get_db();

/* ========= Utils ========= */
function db_has_table(PDO $db, $table){
    try { @$db->query("SELECT 1 FROM `$table` LIMIT 1"); return true; } catch(Exception $e){}
    try { $s = @$db->query("PRAGMA table_info(".$db->quote($table).")"); return (bool)$s; } catch(Exception $e){ return false; }
}
function table_cols(PDO $db, $table){
    $cols = [];
    try {
        $s = @$db->query("PRAGMA table_info(".$db->quote($table).")");
        if ($s) foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) if (isset($c['name'])) $cols[] = strtolower($c['name']);
    } catch(Exception $e){}
    if (!$cols) {
        try {
            $s = @$db->query("DESCRIBE `$table`");
            if ($s) foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) if (isset($c['Field'])) $cols[] = strtolower($c['Field']);
        } catch(Exception $e){}
    }
    return $cols;
}
function rows(PDO $db,$sql,$params=[]){
    try{ $s=$db->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }catch(Exception $e){ return []; }
}
function scalar(PDO $db,$sql,$params=[],$default=0){
    try{ $s=$db->prepare($sql); $s->execute($params); $v=$s->fetchColumn(); return is_null($v)?$default:(float)$v; }catch(Exception $e){ return $default; }
}
function money($v){ return number_format((float)$v,2); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ========= Products schema probes ========= */
if (!db_has_table($db,'products')) { http_response_code(500); exit('Products table not found.'); }
$cols = table_cols($db,'products');

$has_sku  = in_array('sku',$cols,true)  || in_array('code',$cols,true);
$has_name = in_array('name',$cols,true) || in_array('title',$cols,true);

$stock_col = null; foreach (['stock','qty','quantity','onhand','on_hand'] as $c) { if (in_array($c,$cols,true)) { $stock_col=$c; break; } }
$price_col = null; foreach (['price','srp','unit_price'] as $c) { if (in_array($c,$cols,true)) { $price_col=$c; break; } }
$category_col   = null; foreach (['category','category_name','cat','product_category','group','dept','department'] as $c) { if (in_array($c,$cols,true)) { $category_col=$c; break; } }
$unit_col  = null; foreach (['unit','uom','measure','unit_name','unit_of_measure','measurement'] as $c) { if (in_array($c,$cols,true)) { $unit_col=$c; break; } }

$sku_expr   = $has_sku   ? (in_array('sku',$cols,true) ? 'p.sku' : 'p.code') : "''";
$name_expr  = $has_name  ? (in_array('name',$cols,true) ? 'p.name' : 'p.title') : "''";
$stock_expr = $stock_col ? "CAST(p.`$stock_col` AS REAL)" : "0";
$price_expr = $price_col ? "CAST(p.`$price_col` AS REAL)" : "0";
$category_expr   = $category_col    ? "p.`$category_col`" : "NULL";
$unit_expr  = $unit_col   ? "p.`$unit_col`" : "NULL";

/* ========= Filters & defaults ========= */
$tab = $_GET['tab'] ?? 'all';
$search   = trim($_GET['q'] ?? '');
$category_filter  = isset($_GET['category']) ? trim($_GET['category']) : '';
$unit_filter = isset($_GET['unit'])     ? trim($_GET['unit'])     : '';
$critical_threshold = 10;

/* Sorting */
$sort = $_GET['sort'] ?? 'stock';
$dir  = $_GET['dir']  ?? 'asc';
$allowed_sort = ['id','sku','name','stock','price','category','unit'];
if (!in_array($sort, $allowed_sort)) $sort = 'stock';
$dir = in_array($dir, ['asc','desc']) ? $dir : 'asc';
$toggle_dir = $dir === 'asc' ? 'desc' : 'asc';

/* Pagination */
$page    = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset  = ($page - 1) * $per_page;

/* ========= Distinct lists for filters ========= */
$categories  = $category_col ? rows($db, "SELECT DISTINCT $category_expr AS v FROM products p WHERE $category_expr IS NOT NULL AND TRIM($category_expr)<>'' ORDER BY v", []) : [];
$units = $unit_col ? rows($db, "SELECT DISTINCT $unit_expr AS v FROM products p WHERE $unit_expr IS NOT NULL AND TRIM($unit_expr)<>'' ORDER BY v", []) : [];

/* ========= Build WHERE clause ========= */
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(' . $sku_expr . ' LIKE ? OR ' . $name_expr . ' LIKE ?)';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($category_col && $category_filter !== '') { $where[] = "$category_expr = ?"; $params[] = $category_filter; }
if ($unit_col && $unit_filter !== ''){ $where[] = "$unit_expr = ?"; $params[] = $unit_filter; }

if ($tab === 'critical') { $where[] = "($stock_expr <= ?)"; $params[] = $critical_threshold; }
if ($tab === 'zero')     { $where[] = "($stock_expr <= 0)"; }

/* Count total for pagination */
$count_sql = "SELECT COUNT(*) FROM products p WHERE " . implode(' AND ', $where);
$total_items = (int)scalar($db, $count_sql, $params, 0);
$total_pages = max(1, ceil($total_items / $per_page));

/* Main list query */
$order_col = match($sort) {
    'sku'      => $sku_expr,
    'name'     => $name_expr,
    'stock'    => $stock_expr,
    'price'    => $price_expr,
    'category' => $category_expr,
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
        $category_expr   AS category,
        $unit_expr  AS unit
    FROM products p
    WHERE ".implode(' AND ', $where)."
    ORDER BY $order_col $dir, $name_expr ASC, $sku_expr ASC
    LIMIT $per_page OFFSET $offset
";
$items = rows($db, $list_sql, $params);

/* Last updated (if column exists) */
$last_updated = '';
if (in_array('updated_at', $cols) || in_array('updated_at', array_map('strtolower', $cols))) {
    $last_updated = scalar($db, "SELECT MAX(updated_at) FROM products", [], '');
    if ($last_updated) {
        $last_updated = date('M d, Y h:i A', strtotime($last_updated));
    }
}

/* KPIs */
$total_skus      = (int)scalar($db, "SELECT COUNT(*) FROM products", [], 0);
$zero_stock      = (int)scalar($db, "SELECT COUNT(*) FROM products p WHERE $stock_expr <= 0", [], 0);
$critical_stock  = (int)scalar($db, "SELECT COUNT(*) FROM products p WHERE $stock_expr <= ?", [$critical_threshold], 0);

/* ========= Export CSV ========= */
if (isset($_GET['export']) && $_GET['export']==='csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=stocks_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    $headers = ['ID','SKU','Name'];
    if ($category_col) $headers[]='Category';
    if ($unit_col) $headers[]='Unit';
    $headers[] = 'Stock';
    $headers[] = 'Price';
    $headers[] = 'Status';
    fputcsv($out, $headers);

    foreach ($items as $row) {
        $stock = (float)$row['stock'];
        $status = ($stock <= 0) ? 'Zero' : (($stock <= $critical_threshold) ? 'Critical' : 'OK');
        $line = [
            $row['id'],
            $row['sku'],
            $row['name'],
        ];
        if ($category_col) $line[] = $row['category'];
        if ($unit_col) $line[] = $row['unit'];
        $line[] = $stock;
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
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,shrink-to-fit=no">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Stocks Report • Fusion I.T. Solutions</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --brand:#fc3503;
      --muted:#6c757d;
      --spacing-xs:0.5rem;
      --spacing-sm:0.75rem;
      --spacing-md:1rem;
      --spacing-lg:1.5rem;
    }

    * {
      box-sizing: border-box;
      -webkit-box-sizing: border-box;
      -moz-box-sizing: border-box;
    }

    body{
      background:#f6f7fb;
      color:#222;
      font-size:14px;
      line-height:1.5;
      -webkit-text-size-adjust:100%;
      -ms-text-size-adjust:100%;
    }

    .topbar{
      background:linear-gradient(90deg,#ffffff 0%,#f8f9ff 100%);
      padding:var(--spacing-sm);
      border-radius:10px;
      box-shadow:0 2px 6px rgba(16,24,40,0.04);
      display:flex;
      flex-wrap:wrap;
      align-items:center;
      gap:var(--spacing-sm);
    }

    .brand{
      display:flex;
      align-items:center;
      gap:var(--spacing-sm);
      flex:1 1 auto;
      min-width:0;
    }

    .brand img{
      height:40px;
      width:auto;
      max-width:100%;
      object-fit:contain;
      flex-shrink:0;
    }

    .brand-text{
      min-width:0;
      flex:1 1 auto;
    }

    .brand-title{
      font-size:1rem;
      font-weight:700;
      line-height:1.2;
      margin:0;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    .subtitle{
      font-size:0.8rem;
      color:var(--muted);
      margin:0;
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }

    .topbar-actions{
      display:flex;
      gap:var(--spacing-xs);
      flex-wrap:wrap;
      justify-content:flex-end;
      flex-shrink:0;
    }

    .btn,
    .btn-primary,.btn-secondary,.btn-success,.btn-danger,.btn-warning,.btn-info,.btn-dark,.btn-light{
      background-color:var(--brand) !important;
      border-color:var(--brand) !important;
      color:#fff !important;
      white-space:nowrap;
      font-size:0.875rem;
      padding:0.375rem 0.75rem;
    }

    .btn:hover,.btn:focus,.btn:active{
      background-color:var(--brand) !important;
      border-color:var(--brand) !important;
      color:#fff !important;
      opacity:0.9;
    }

    .btn-outline-primary,.btn-outline-secondary,.btn-outline-success,.btn-outline-danger,.btn-outline-warning,.btn-outline-info,.btn-outline-dark,.btn-outline-light{
      background-color:transparent !important;
      border-color:var(--brand) !important;
      color:var(--brand) !important;
    }

    .btn-outline-primary:hover,.btn-outline-secondary:hover,.btn-outline-success:hover,.btn-outline-danger:hover,.btn-outline-warning:hover,.btn-outline-info:hover,.btn-outline-dark:hover,.btn-outline-light:hover{
      background-color:var(--brand) !important;
      color:#fff !important;
    }

    .card-quiet{background:transparent;border:0}

    .kpi{
      border:0;
      border-radius:12px;
      box-shadow:0 4px 18px rgba(16,24,40,.06);
      background:#fff;
      height:100%;
    }

    .kpi .label{
      color:#6b7280;
      font-size:0.8rem;
      line-height:1.2;
    }

    .kpi .value{
      font-size:1.25rem;
      font-weight:800;
      line-height:1.2;
      margin:0.25rem 0;
    }

    .kpi .hint{
      font-size:0.75rem;
      color:#9ca3af;
      line-height:1.2;
    }

    .kpi .icon{
      font-size:1.15rem;
      flex-shrink:0;
    }

    .status-badge{
      font-size:0.7rem;
      padding:0.2rem 0.5rem;
      border-radius:24px;
      white-space:nowrap;
      display:inline-block;
    }

    .status-zero{background:#ffe2e2;color:#b42318}
    .status-critical{background:#fff3cd;color:#ad6800}
    .status-ok{background:#e6ffed;color:#057647}

    .table{
      font-size:0.875rem;
      margin-bottom:0;
    }

    .table thead th{
      white-space:nowrap;
      font-size:0.8rem;
      padding:0.75rem 0.5rem;
      vertical-align:middle;
    }

    .table tbody td{
      padding:0.75rem 0.5rem;
      vertical-align:middle;
    }

    .number{
      white-space:nowrap;
      font-variant-numeric:tabular-nums;
    }

    mark {
      background: #fff3cd;
      padding: 1px 4px;
      border-radius: 3px;
    }

    .table-responsive{
      overflow-x:auto;
      -webkit-overflow-scrolling:touch;
      width:100%;
    }

    .form-control,.form-select{
      font-size:0.875rem;
    }

    .pagination{
      flex-wrap:wrap;
      gap:0.25rem;
    }

    .page-link{
      font-size:0.875rem;
      padding:0.375rem 0.75rem;
    }

    /* Mobile optimizations */
    @media (max-width:575.98px){
      body{font-size:13px}

      .topbar{
        padding:var(--spacing-xs);
      }

      .brand img{
        height:32px;
      }

      .brand-title{
        font-size:0.9rem;
      }

      .subtitle{
        font-size:0.7rem;
      }

      .topbar-actions{
        width:100%;
        flex-basis:100%;
      }

      .topbar-actions .btn{
        flex:1 1 auto;
        font-size:0.75rem;
        padding:0.35rem 0.5rem;
      }

      .kpi .value{
        font-size:1.1rem;
      }

      .kpi .label{
        font-size:0.75rem;
      }

      .kpi .hint{
        font-size:0.7rem;
      }

      .kpi .icon{
        font-size:1rem;
      }

      .kpi{
        padding:0.75rem !important;
      }

      .table{
        font-size:0.75rem;
      }

      .table thead th{
        font-size:0.7rem;
        padding:0.5rem 0.35rem;
      }

      .table tbody td{
        padding:0.5rem 0.35rem;
      }

      .status-badge{
        font-size:0.65rem;
        padding:0.15rem 0.4rem;
      }

      .form-control,.form-select{
        font-size:0.8rem;
      }

      .form-label{
        font-size:0.75rem;
      }

      .btn-sm{
        font-size:0.75rem;
        padding:0.3rem 0.6rem;
      }

      .page-link{
        font-size:0.75rem;
        padding:0.3rem 0.5rem;
      }

      h1,h2,h3,h4,h5,h6{
        font-size:1rem;
      }
    }

    /* Tablet optimizations */
    @media (min-width:576px) and (max-width:991.98px){
      .topbar-actions .btn{
        font-size:0.8rem;
      }

      .kpi .value{
        font-size:1.15rem;
      }
    }

    /* Better table scrolling on mobile */
    @media (max-width:767.98px){
      .table-responsive{
        margin:0 -0.5rem;
      }

      .card{
        overflow:hidden;
      }
    }

    /* Cross-browser compatibility */
    .table-responsive::-webkit-scrollbar{
      height:8px;
    }

    .table-responsive::-webkit-scrollbar-track{
      background:#f1f1f1;
      border-radius:4px;
    }

    .table-responsive::-webkit-scrollbar-thumb{
      background:#888;
      border-radius:4px;
    }

    .table-responsive::-webkit-scrollbar-thumb:hover{
      background:#555;
    }

    /* Firefox scrollbar */
    .table-responsive{
      scrollbar-width:thin;
      scrollbar-color:#888 #f1f1f1;
    }

    /* Prevent layout shift */
    img{
      max-width:100%;
      height:auto;
    }

    /* Better touch targets on mobile */
    @media (max-width:767.98px){
      a,button,.btn{
        min-height:44px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
      }

      .page-link{
        min-width:44px;
        min-height:44px;
      }
    }

    /* Print styles */
    @media print{
      .topbar-actions,.filters,nav{
        display:none !important;
      }

      .table{
        font-size:10pt;
      }

      body{
        background:#fff;
      }
    }
  </style>
</head>
<body>
<div class="container py-4">

  <!-- Header -->
  <div class="topbar mb-3">
    <div class="brand">
      <?php if (file_exists(__DIR__.'/assets/logo.png')): ?>
        <img src="assets/logo.png" alt="logo">
      <?php else: ?>
        <div style="width:40px;height:40px;border-radius:8px;background:var(--brand);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0">F</div>
      <?php endif; ?>
      <div class="brand-text">
        <div class="brand-title">Fusion I.T. Solutions</div>
        <div class="subtitle">Stocks Report</div>
      </div>
    </div>
    <div class="topbar-actions">
      <a class="btn btn-outline-secondary btn-sm" href="index.php">Dashboard</a>
      <a class="btn btn-outline-secondary btn-sm" href="?export=csv&tab=<?=urlencode($tab)?>&q=<?=urlencode($search)?>&category=<?=urlencode($category_filter)?>&unit=<?=urlencode($unit_filter)?>">Export</a>
      <a class="btn btn-outline-secondary btn-sm" href="logout.php">Logout</a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-2 mb-3">
    <div class="col-12 col-md-4">
      <div class="kpi p-3">
        <div class="d-flex justify-content-between align-items-start mb-1"><span class="label">Total SKUs</span><span class="icon">📦</span></div>
        <div class="value"><?= number_format($total_skus) ?></div>
        <div class="hint">Products in catalog</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="kpi p-3">
        <div class="d-flex justify-content-between align-items-start mb-1"><span class="label">Critical (≤ 10)</span><span class="icon">⚠️</span></div>
        <div class="value"><?= number_format($critical_stock) ?></div>
        <div class="hint">Low stock items</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="kpi p-3">
        <div class="d-flex justify-content-between align-items-start mb-1"><span class="label">Zero Stock</span><span class="icon">⛔</span></div>
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
  <div class="card card-quiet p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label small mb-1">View</label>
        <select name="tab" class="form-select form-select-sm">
          <option value="all"     <?= $tab==='all'?'selected':'' ?>>All Products</option>
          <option value="critical"<?= $tab==='critical'?'selected':'' ?>>Critical (≤ 10)</option>
          <option value="zero"    <?= $tab==='zero'?'selected':'' ?>>Zero Stock</option>
        </select>
      </div>

      <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label small mb-1">Product</label>
        <input type="text" class="form-control form-control-sm" name="q" placeholder="Search SKU or Name" value="<?= h($search) ?>">
      </div>

      <?php if ($category_col): ?>
      <div class="col-6 col-sm-6 col-md-2">
        <label class="form-label small mb-1"><?= h(ucwords(str_replace('_',' ',$category_col))) ?></label>
        <select name="category" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach ($categories as $c): $cv=$c['v']; ?>
            <option value="<?= h($cv) ?>" <?= ($category_filter!=='' && $category_filter===$cv)?'selected':'' ?>><?= h($cv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($unit_col): ?>
      <div class="col-6 col-sm-6 col-md-2">
        <label class="form-label small mb-1"><?= h(ucwords(str_replace('_',' ',$unit_col))) ?></label>
        <select name="unit" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach ($units as $u): $uv=$u['v']; ?>
            <option value="<?= h($uv) ?>" <?= ($unit_filter!=='' && $unit_filter===$uv)?'selected':'' ?>><?= h($uv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="col-12 col-sm-6 col-md-2 d-grid">
        <button class="btn btn-primary btn-sm">Apply Filters</button>
      </div>
    </form>
  </div>

  <!-- Results count -->
  <div class="mb-2">
    <strong><?= number_format($total_items) ?></strong> item<?= $total_items === 1 ? '' : 's' ?> found
    <?php if ($search || $category_filter || $unit_filter || $tab !== 'all'): ?>
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
            $sort_url = function($col) use ($tab, $search, $category_filter, $unit_filter, $sort, $toggle_dir) {
                $q = $_GET;
                $q['sort'] = $col;
                $q['dir']  = ($sort === $col) ? $toggle_dir : 'asc';
                return '?' . http_build_query($q);
            };
            $sort_indicator = function($col) use ($sort, $dir) {
                if ($sort !== $col) return '';
                return $dir === 'asc' ? ' ↑' : ' ↓';
            };
            ?>
            <th><a href="<?= $sort_url('id') ?>" class="text-dark text-decoration-none">ID<?= $sort_indicator('id') ?></a></th>
            <th><a href="<?= $sort_url('sku') ?>" class="text-dark text-decoration-none">SKU<?= $sort_indicator('sku') ?></a></th>
            <th><a href="<?= $sort_url('name') ?>" class="text-dark text-decoration-none">Name<?= $sort_indicator('name') ?></a></th>
            <?php if ($category_col): ?>
              <th><a href="<?= $sort_url('category') ?>" class="text-dark text-decoration-none">Category<?= $sort_indicator('category') ?></a></th>
            <?php endif; ?>
            <?php if ($unit_col): ?>
              <th><a href="<?= $sort_url('unit') ?>" class="text-dark text-decoration-none">Unit<?= $sort_indicator('unit') ?></a></th>
            <?php endif; ?>
            <th class="text-end">
              <a href="<?= $sort_url('stock') ?>" class="text-dark text-decoration-none">Stock<?= $sort_indicator('stock') ?></a>
            </th>
            <th class="text-center">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="<?= 4 + (int)!!$category_col + (int)!!$unit_col ?>" class="text-center text-muted py-4">
              No products found matching your criteria.
            </td>
          </tr>
        <?php else: foreach ($items as $row):
            $stock = (float)$row['stock'];
            $is_zero = $stock <= 0;
            $is_critical = $stock <= $critical_threshold && !$is_zero;

            // Highlight search term
            $highlight = function($text) use ($search) {
                if (!$search) return h($text);
                return preg_replace(
                    '/(' . preg_quote($search, '/') . ')/i',
                    '<mark>$1</mark>',
                    h($text)
                );
            };
        ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><?= $highlight($row['sku']) ?></td>
            <td><?= $highlight($row['name']) ?></td>
            <?php if ($category_col): ?><td><?= h($row['category']) ?></td><?php endif; ?>
            <?php if ($unit_col): ?><td><?= h($row['unit']) ?></td><?php endif; ?>
            <td class="text-end number fw-bold"><?= rtrim(rtrim(number_format($stock, 2), '0'), '.') ?></td>
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
    <nav aria-label="Page navigation" class="mt-3">
      <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php
        $params_no_page = $_GET;
        unset($params_no_page['page']);
        $base_url = '?' . http_build_query($params_no_page) . '&page=';

        $prev_disabled = $page <= 1 ? ' disabled' : '';
        $next_disabled = $page >= $total_pages ? ' disabled' : '';
        ?>
        <li class="page-item<?= $prev_disabled ?>">
          <a class="page-link" href="<?= $page > 1 ? $base_url . ($page-1) : '#' ?>" <?= $prev_disabled?'tabindex="-1" aria-disabled="true"':'' ?>>Prev</a>
        </li>

        <?php
        $delta = 2;
        $start = max(1, $page - $delta);
        $end   = min($total_pages, $page + $delta);

        if ($start > 1): ?>
          <li class="page-item"><a class="page-link" href="<?= $base_url ?>1">1</a></li>
          <?php if ($start > 2): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; ++$p): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base_url . $p ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>

        <?php if ($end < $total_pages): ?>
          <?php if ($end < $total_pages - 1): ?>
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
