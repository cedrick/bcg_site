<?php
// sales_report.php - Sales Report with date filter, cashier name, CSV export, print per sale (WITH ITEMS), sorting + PAGINATION
date_default_timezone_set('Asia/Manila');
require 'auth.php';
require_login();

$db = get_db();

/* ===== Pagination Settings ===== */
$perPage = 20; // ← you can change this
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $perPage;

/* ===== Date Range Filter ===== */
$fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate = $_GET['to'] ?? date('Y-m-d');

// Sanitize dates
$fromDate = date('Y-m-d', strtotime($fromDate));
$toDate = date('Y-m-d', strtotime($toDate));

/* ===== Count total matching records (for pagination) ===== */
$countSql = "
    SELECT COUNT(*)
    FROM sales s
    WHERE DATE(s.created_at) BETWEEN ? AND ?
";
$countStmt = $db->prepare($countSql);
$countStmt->execute([$fromDate, $toDate]);
$totalRecords = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

/* ===== Calculate FULL date-range totals for summary ===== */
$totalRevenue = 0.00;
$totalVat = 0.00;
$avgSale = 0.00;

if ($totalRecords > 0) {
    $sumSql = "
        SELECT
            COALESCE(SUM(total), 0) AS total_revenue,
            COALESCE(SUM(vat),   0)   AS total_vat
        FROM sales s
        WHERE DATE(s.created_at) BETWEEN ? AND ?
    ";
    $sumStmt = $db->prepare($sumSql);
    $sumStmt->execute([$fromDate, $toDate]);
    $sums = $sumStmt->fetch(PDO::FETCH_ASSOC);

    $totalRevenue = (float)($sums['total_revenue'] ?? 0);
        $totalVat = (float)($sums['total_vat'] ?? 0);
     $avgSale = $totalRevenue / $totalRecords;
}

/* ===== Fetch Sales Data (paginated) ===== */
$salesSql = "
    SELECT
        s.id,
        s.created_at,
        s.seller,
        s.subtotal,
        s.discount_pct,
        s.discount_amount,
        s.subtotal_after_discount,
        s.vat,
        s.total,
        s.paid,
        s.change_amount,
        s.vat_enabled
    FROM sales s
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC
    LIMIT ? OFFSET ?
";

$salesStmt = $db->prepare($salesSql);
$salesStmt->execute([$fromDate, $toDate, $perPage, $offset]);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== CSV Export (still exports ALL records, not just current page) ===== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Ymd_His') . '.csv"');

    $csvOut = fopen('php://output', 'w');

    fputcsv($csvOut, [
        'Sale ID',
        'Date & Time',
        'Cashier / Seller',
        'Subtotal',
        'Discount %',
        'Discount Amount',
        'Subtotal after Discount',
        'VAT (12%)',
        'Total',
        'Amount Paid',
        'Change',
        'VAT Enabled'
    ]);

    // For CSV we fetch ALL records (ignoring pagination)
    $csvStmt = $db->prepare(str_replace('LIMIT ? OFFSET ?', '', $salesSql));
    $csvStmt->execute([$fromDate, $toDate]);
    while ($row = $csvStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($csvOut, [
            $row['id'],
            date('Y-m-d H:i:s', strtotime($row['created_at'])),
            $row['seller'] ?: '—',
            number_format($row['subtotal'], 2),
            number_format($row['discount_pct'], 2) . '%',
            number_format($row['discount_amount'], 2),
            number_format($row['subtotal_after_discount'], 2),
            number_format($row['vat'], 2),
            number_format($row['total'], 2),
            number_format($row['paid'], 2),
            number_format($row['change_amount'], 2),
            $row['vat_enabled'] ? 'Yes' : 'No'
        ]);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Sales Report • Fusion I.T. Solutions</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* ===== CSS Variables for easier theming ===== */
    :root {
      --brand: #fc3503;
      --brand-hover: #d32f02;
      --muted: #6c757d;
      --bg-light: #f8f9fa;
      --white: #ffffff;
      --shadow-sm: 0 2px 8px rgba(16,24,40,0.06);
      --shadow-md: 0 4px 16px rgba(0,0,0,0.06);
      --shadow-lg: 0 2px 12px rgba(0,0,0,0.05);
      --border-radius: 10px;
      --border-radius-lg: 12px;
      --spacing-xs: 0.5rem;
      --spacing-sm: 0.75rem;
      --spacing-md: 1rem;
      --spacing-lg: 1.25rem;
      --spacing-xl: 1.5rem;
    }

    /* ===== Base Styles ===== */
    * {
      box-sizing: border-box;
    }

    body {
      background: var(--bg-light);
      color: #222;
      font-size: 16px;
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* ===== Container Responsive Adjustments ===== */
    .container {
      width: 100%;
      max-width: 100%;
      padding-right: var(--spacing-md);
      padding-left: var(--spacing-md);
    }

    @media (min-width: 576px) {
      .container {
        max-width: 540px;
      }
    }

    @media (min-width: 768px) {
      .container {
        max-width: 720px;
      }
    }

    @media (min-width: 992px) {
      .container {
        max-width: 960px;
      }
    }

    @media (min-width: 1200px) {
      .container {
        max-width: 1140px;
      }
    }

    @media (min-width: 1400px) {
      .container {
        max-width: 1320px;
      }
    }

    /* ===== Topbar ===== */
    .topbar {
      background: linear-gradient(90deg, var(--white) 0%, #f8f9ff 100%);
      padding: var(--spacing-md);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow-sm);
      margin-bottom: var(--spacing-lg);
      flex-wrap: wrap;
      gap: var(--spacing-md);
    }

    .brand {
      display: flex;
      align-items: center;
      gap: var(--spacing-sm);
      flex: 1 1 auto;
      min-width: 200px;
    }

    .brand img {
      height: 44px;
      width: auto;
      object-fit: contain;
      max-width: 100%;
    }

    .brand-title {
      font-size: 1.2rem;
      font-weight: 700;
      line-height: 1.2;
      word-break: break-word;
    }

    .subtitle {
      font-size: 0.9rem;
      color: var(--muted);
    }

    .topbar-actions {
      display: flex;
      gap: var(--spacing-xs);
      align-items: center;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    /* ===== KPI Cards ===== */
    .kpi {
      border-radius: var(--border-radius-lg);
      box-shadow: var(--shadow-md);
      background: var(--white);
      padding: var(--spacing-lg);
      text-align: center;
      min-height: 100px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .kpi .label {
      color: #6b7280;
      font-size: 0.85rem;
      margin-bottom: 0.5rem;
      font-weight: 500;
    }

    .kpi .value {
      font-size: clamp(1.2rem, 4vw, 1.6rem);
      font-weight: 700;
      color: var(--brand);
      word-break: break-word;
    }

    /* ===== Cards ===== */
    .card {
      border-radius: var(--border-radius);
      box-shadow: var(--shadow-lg);
      border: none;
      margin-bottom: var(--spacing-lg);
    }

    /* ===== Table Responsive Container ===== */
    .table-responsive {
      border-radius: var(--border-radius);
      overflow-x: auto;
      overflow-y: hidden;
      box-shadow: var(--shadow-lg);
      -webkit-overflow-scrolling: touch;
      width: 100%;
    }

    /* ===== Table Styles ===== */
    .table {
      margin-bottom: 0;
      width: 100%;
      min-width: 800px;
    }

    .table th {
      background: var(--brand) !important;
      color: var(--white) !important;
      white-space: nowrap;
      position: sticky;
      top: 0;
      z-index: 10;
      font-weight: 600;
      font-size: 0.9rem;
      padding: 0.75rem;
      vertical-align: middle;
    }

    .table td {
      padding: 0.75rem;
      vertical-align: middle;
      font-size: 0.9rem;
    }

    .table th.sortable {
      cursor: pointer;
      user-select: none;
      transition: background-color 0.2s ease;
    }

    .table th.sortable:hover {
      background: var(--brand-hover) !important;
    }

    .table th.sortable::after {
      content: ' ↕';
      opacity: 0.5;
      font-size: 0.9em;
    }

    .table th.sortable.asc::after {
      content: ' ↑';
      opacity: 1;
    }

    .table th.sortable.desc::after {
      content: ' ↓';
      opacity: 1;
    }

    /* ===== Empty State ===== */
    .empty-state {
      padding: 3rem 1rem;
      text-align: center;
      color: var(--muted);
      background: var(--white);
      border-radius: var(--border-radius-lg);
      box-shadow: var(--shadow-lg);
    }

    .empty-state h5 {
      font-size: 1.25rem;
      margin-bottom: 0.5rem;
    }

    /* ===== Buttons ===== */
    .btn-brand,
    .btn-brand:hover,
    .btn-brand:focus,
    .btn-brand:active {
      background-color: var(--brand) !important;
      border-color: var(--brand) !important;
      color: var(--white) !important;
      transition: all 0.2s ease;
    }

    .btn-brand:hover {
      background-color: var(--brand-hover) !important;
      border-color: var(--brand-hover) !important;
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(252, 53, 3, 0.2);
    }

    .btn-sm {
      padding: 0.375rem 0.75rem;
      font-size: 0.875rem;
      white-space: nowrap;
    }

    /* ===== Pagination ===== */
    .pagination {
      flex-wrap: wrap;
      gap: 0.25rem;
      margin: 0;
    }

    .pagination .page-link {
      color: var(--brand);
      border-color: #dee2e6;
      padding: 0.5rem 0.75rem;
      min-width: 44px;
      text-align: center;
    }

    .pagination .page-item.active .page-link {
      background-color: var(--brand);
      border-color: var(--brand);
      color: var(--white);
    }

    .pagination .page-item.disabled .page-link {
      color: #6c757d;
      pointer-events: none;
    }

    /* ===== Print Styles ===== */
    .print-area {
      display: none;
    }

    @media print {
      .no-print {
        display: none !important;
      }

      .print-area {
        display: block !important;
      }

      body {
        background: var(--white) !important;
        margin: 0 !important;
        padding: 0 !important;
      }

      @page {
        size: auto;
        margin: 8mm 5mm 10mm 5mm;
      }

      .table {
        min-width: auto !important;
      }
    }

    /* ===== Print Template ===== */
    #printTemplate {
      font-family: 'Courier New', Courier, monospace;
      max-width: 380px;
      margin: 0 auto;
      padding: 12px 10px;
      font-size: 11px;
      line-height: 1.38;
      color: #000;
      background: var(--white);
    }

    #printTemplate h4 {
      margin: 0 0 4px 0;
      text-align: center;
      font-size: 16px;
      color: var(--brand);
      font-weight: bold;
    }

    .receipt-title {
      text-align: center;
      font-size: 11px;
      color: #444;
      margin-bottom: 12px;
    }

    .info-line {
      margin: 2px 0;
    }

    .info-line strong {
      display: inline-block;
      width: 100px;
    }

    table#print-items {
      width: 100%;
      border-collapse: collapse;
      margin: 12px 0 16px 0;
    }

    table#print-items thead th {
      border-bottom: 2px solid #000;
      padding: 4px 0;
      font-weight: bold;
      text-align: left;
      font-size: 11px;
    }

    table#print-items td {
      padding: 3px 0;
      vertical-align: top;
      font-size: 11px;
    }

    table#print-items .qty {
      text-align: center;
      width: 35px;
    }

    table#print-items .price,
    table#print-items .amount {
      text-align: right;
      width: 85px;
    }

    .totals-section {
      margin-top: 12px;
      border-top: 2px solid #000;
      padding-top: 8px;
    }

    .total-row {
      display: flex;
      justify-content: space-between;
      margin: 3px 0;
      font-size: 12px;
    }

    .total-row.grand-total {
      font-weight: bold;
      font-size: 13px;
      padding-top: 6px;
      border-top: 1px solid #000;
    }

    .footer-text {
      text-align: center;
      margin-top: 20px;
      font-size: 10px;
      color: #555;
    }

    /* ===== Mobile Responsive Adjustments ===== */
    @media (max-width: 767px) {
      .container {
        padding-right: 0.75rem;
        padding-left: 0.75rem;
      }

      .topbar {
        padding: 0.75rem;
        flex-direction: column;
        align-items: stretch;
      }

      .brand {
        justify-content: center;
        text-align: center;
        flex-direction: column;
        gap: 0.5rem;
      }

      .brand-title {
        font-size: 1rem;
      }

      .topbar-actions {
        width: 100%;
        justify-content: center;
      }

      .topbar-actions .btn {
        flex: 1 1 auto;
        min-width: 80px;
        font-size: 0.75rem;
        padding: 0.5rem 0.5rem;
      }

      .kpi {
        padding: 1rem;
        min-height: 80px;
      }

      .kpi .label {
        font-size: 0.75rem;
      }

      .kpi .value {
        font-size: 1.1rem;
      }

      .table th,
      .table td {
        padding: 0.5rem;
        font-size: 0.8rem;
      }

      .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
      }

      .pagination .page-link {
        padding: 0.375rem 0.5rem;
        font-size: 0.875rem;
        min-width: 38px;
      }

      /* Make form controls stack on mobile */
      .card-body form .row {
        flex-direction: column;
      }

      .card-body form .col-12,
      .card-body form .col-md-4,
      .card-body form .col-lg-3,
      .card-body form .col-lg-2 {
        width: 100%;
        margin-bottom: 0.75rem;
      }
    }

    /* ===== Tablet Responsive Adjustments ===== */
    @media (min-width: 768px) and (max-width: 991px) {
      .topbar-actions {
        gap: 0.5rem;
      }

      .topbar-actions .btn {
        font-size: 0.8rem;
        padding: 0.5rem 0.75rem;
      }

      .kpi .value {
        font-size: 1.4rem;
      }
    }

    /* ===== Large Desktop Adjustments ===== */
    @media (min-width: 1400px) {
      .container {
        max-width: 1400px;
      }
    }

    /* ===== Cross-browser Compatibility ===== */
    /* Firefox scrollbar */
    .table-responsive {
      scrollbar-width: thin;
      scrollbar-color: var(--brand) #f1f1f1;
    }

    /* Webkit scrollbar (Chrome, Safari, Edge) */
    .table-responsive::-webkit-scrollbar {
      height: 8px;
    }

    .table-responsive::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb {
      background: var(--brand);
      border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb:hover {
      background: var(--brand-hover);
    }

    /* IE11 specific fixes */
    @media screen and (-ms-high-contrast: active), (-ms-high-contrast: none) {
      .table-responsive {
        overflow-x: scroll;
      }
    }

    /* Safari-specific fixes */
    @supports (-webkit-appearance: none) {
      .table {
        -webkit-transform: translateZ(0);
      }
    }

    /* Ensure buttons are accessible size on touch devices */
    @media (pointer: coarse) {
      .btn {
        min-height: 44px;
        min-width: 44px;
      }

      .pagination .page-link {
        min-height: 44px;
        min-width: 44px;
      }
    }

    /* Dark mode support (optional, respects system preference) */
    @media (prefers-color-scheme: dark) {
      /* You can uncomment this section if dark mode support is desired */
      /*
      :root {
        --bg-light: #1a1a1a;
        --white: #2d2d2d;
      }

      body {
        background: var(--bg-light);
        color: #e0e0e0;
      }
      */
    }

    /* Reduced motion for accessibility */
    @media (prefers-reduced-motion: reduce) {
      * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
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
        <div style="width:44px;height:44px;border-radius:8px;background:var(--brand);color:white;font-weight:700;display:flex;align-items:center;justify-content:center;">F</div>
      <?php endif; ?>
      <div>
        <div class="brand-title">Fusion I.T. Solutions</div>
        <div class="subtitle">Sales Report</div>
      </div>
    </div>
    <div class="topbar-actions">
      <a href="index.php" class="btn btn-brand btn-sm no-print">Dashboard</a>
      <a href="pos.php" class="btn btn-brand btn-sm no-print">Back to POS</a>
      <a href="?export=csv&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="btn btn-brand btn-sm no-print">Export CSV</a>
      <a href="logout.php" class="btn btn-outline-danger btn-sm no-print">Logout</a>
    </div>
  </div>

  <!-- Date Filter -->
  <div class="card no-print">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-12 col-md-4 col-lg-3">
          <label class="form-label small mb-1">From Date</label>
          <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
        </div>
        <div class="col-12 col-md-4 col-lg-3">
          <label class="form-label small mb-1">To Date</label>
          <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
        </div>
        <div class="col-12 col-md-4 col-lg-2 d-grid">
          <button type="submit" class="btn btn-brand">Filter</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Summary Stats - NOW USING FULL RANGE TOTALS -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-3">
      <div class="kpi">
        <div class="label">Total Sales</div>
        <div class="value"><?= number_format($totalRecords) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
      <div class="kpi">
        <div class="label">Total Revenue</div>
        <div class="value">₱ <?= number_format($totalRevenue, 2) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
      <div class="kpi">
        <div class="label">Total VAT Collected</div>
        <div class="value">₱ <?= number_format($totalVat, 2) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
      <div class="kpi">
        <div class="label">Average Sale</div>
        <div class="value">₱ <?= number_format($avgSale, 2) ?></div>
      </div>
    </div>
  </div>

  <!-- Sales Table -->
  <?php if (empty($sales)): ?>
    <div class="empty-state">
      <h5>No sales found in the selected date range.</h5>
      <p class="text-muted">Try adjusting the date filter or make some sales in POS.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover table-striped mb-0" id="salesTable">
            <thead>
              <tr>
                <th class="sortable" data-col="id">ID</th>
                <th class="sortable" data-col="date">Date & Time</th>
                <th class="sortable" data-col="seller">Cashier</th>
                <th class="sortable text-end" data-col="subtotal">Subtotal</th>
                <th class="text-end">Discount</th>
                <th class="sortable text-end" data-col="vat">VAT</th>
                <th class="sortable text-end fw-bold" data-col="total">Total</th>
                <th class="sortable text-end" data-col="paid">Paid</th>
                <th class="sortable text-end" data-col="change">Change</th>
                <th class="no-print text-center">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($sales as $sale): ?>

              <?php
              // Fetch items - try different price column names
              $items = [];
              try {
                  $itemsStmt = $db->prepare("
                      SELECT
                          p.name AS product_name,
                          si.qty AS quantity,
                          COALESCE(si.unit_price, si.price, 0) AS unit_price
                      FROM sales_items si
                      LEFT JOIN products p ON si.product_id = p.id
                      WHERE si.sale_id = ?
                      ORDER BY si.id
                  ");
                  $itemsStmt->execute([$sale['id']]);
                  $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
              } catch (Exception $e) {
                  // silent fail → empty array
              }

              $itemsJson = htmlspecialchars(json_encode($items), ENT_QUOTES, 'UTF-8');
              ?>

              <tr data-id="<?= $sale['id'] ?>" data-items='<?= $itemsJson ?>'>
                <td><?= htmlspecialchars($sale['id']) ?></td>
                <td data-value="<?= strtotime($sale['created_at']) ?>">
                  <?= date('Y-m-d H:i:s', strtotime($sale['created_at'])) ?>
                </td>
                <td data-value="<?= htmlspecialchars(strtolower($sale['seller'] ?: '—')) ?>">
                  <?= htmlspecialchars($sale['seller'] ?: '—') ?>
                  <?php if (empty($sale['seller'])): ?>
                    <span class="badge bg-warning text-dark ms-1 small">No cashier</span>
                  <?php endif; ?>
                </td>
                <td class="text-end" data-value="<?= $sale['subtotal'] ?>">₱ <?= number_format($sale['subtotal'], 2) ?></td>
                <td class="text-end">
                  <?= $sale['discount_pct'] > 0 ? number_format($sale['discount_pct'], 2) . '%' : '—' ?>
                  <?php if ($sale['discount_amount'] > 0): ?>
                    <br><small class="text-muted">-₱ <?= number_format($sale['discount_amount'], 2) ?></small>
                  <?php endif; ?>
                </td>
                <td class="text-end" data-value="<?= $sale['vat'] ?>">₱ <?= number_format($sale['vat'], 2) ?></td>
                <td class="text-end fw-bold" data-value="<?= $sale['total'] ?>">₱ <?= number_format($sale['total'], 2) ?></td>
                <td class="text-end" data-value="<?= $sale['paid'] ?>">₱ <?= number_format($sale['paid'], 2) ?></td>
                <td class="text-end <?= $sale['change_amount'] > 0 ? 'text-success' : '' ?>" data-value="<?= $sale['change_amount'] ?>">
                  ₱ <?= number_format($sale['change_amount'], 2) ?>
                </td>
                <td class="text-center no-print">
                  <button class="btn btn-sm btn-brand print-btn"
                          data-id="<?= $sale['id'] ?>"
                          data-date="<?= date('Y-m-d H:i:s', strtotime($sale['created_at'])) ?>"
                          data-seller="<?= htmlspecialchars($sale['seller'] ?: 'Not recorded') ?>"
                          data-total="<?= number_format($sale['total'], 2) ?>"
                          data-paid="<?= number_format($sale['paid'], 2) ?>"
                          data-change="<?= number_format($sale['change_amount'], 2) ?>">
                    Print
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination Controls -->
      <?php if ($totalPages > 1): ?>
      <nav aria-label="Sales pagination" class="mt-4 no-print">
        <ul class="pagination justify-content-center">
          <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?from=<?=urlencode($fromDate)?>&to=<?=urlencode($toDate)?>&page=<?= $currentPage-1 ?>" aria-label="Previous">
              <span aria-hidden="true">&laquo; Prev</span>
            </a>
          </li>

          <?php
          $pageWindow = 2; // show 2 pages before & after current
          $startPage = max(1, $currentPage - $pageWindow);
          $endPage = min($totalPages, $currentPage + $pageWindow);

          if ($startPage > 1): ?>
            <li class="page-item"><a class="page-link" href="?from=<?=urlencode($fromDate)?>&to=<?=urlencode($toDate)?>&page=1">1</a></li>
            <?php if ($startPage > 2): ?>
              <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
          <?php endif; ?>

          <?php for ($p = $startPage; $p <= $endPage; ++$p): ?>
            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
              <a class="page-link" href="?from=<?=urlencode($fromDate)?>&to=<?=urlencode($toDate)?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
              <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            <li class="page-item"><a class="page-link" href="?from=<?=urlencode($fromDate)?>&to=<?=urlencode($toDate)?>&page=<?= $totalPages ?>"><?= $totalPages ?></a></li>
          <?php endif; ?>

          <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="?from=<?=urlencode($fromDate)?>&to=<?=urlencode($toDate)?>&page=<?= $currentPage+1 ?>" aria-label="Next">
              <span aria-hidden="true">Next &raquo;</span>
            </a>
          </li>
        </ul>
      </nav>
      <?php endif; ?>

      <div class="text-center text-muted small mt-2 no-print">
        Showing <?= count($sales) ?> of <?= number_format($totalRecords) ?> sales
        (Page <?= $currentPage ?> of <?= $totalPages ?>)
      </div>
    </div>
  <?php endif; ?>

</div>

<!-- Hidden print template -->
<div id="printTemplate" class="print-area">
  <div style="font-family: 'Courier New', Courier, monospace; max-width: 380px; margin: 0 auto; padding: 12px 10px; font-size: 11px; line-height: 1.38; color: #000; background: white;">

    <!-- Header -->
    <div style="text-align: center; margin-bottom: 10px;">
      <h4 style="margin: 0 0 4px 0; font-size: 16px; color: #fc3503; font-weight: bold;">
        Fusion I.T. Solutions
      </h4>
      <div style="font-size: 11px; color: #444;">
        Sales Receipt
      </div>
    </div>

    <!-- Sale Info -->
    <div style="margin-bottom: 14px;">
      <div class="info-line"><strong>Sale ID:</strong> <span id="print-id"></span></div>
      <div class="info-line"><strong>Date & Time:</strong> <span id="print-date"></span></div>
      <div class="info-line"><strong>Cashier:</strong> <span id="print-seller"></span></div>
    </div>

    <!-- Items -->
    <table id="print-items">
      <thead>
        <tr>
          <th style="text-align:left; padding:0 0 5px 0;">Item</th>
          <th class="qty" style="text-align:center;">Qty</th>
          <th class="price" style="text-align:right;">Price</th>
          <th class="amount" style="text-align:right;">Amount</th>
        </tr>
      </thead>
      <tbody id="print-items-body"></tbody>
    </table>

    <!-- Totals -->
    <div class="totals-section">
      <div class="total-row grand-total">
        <span>Total:</span>
        <span>₱ <span id="print-total"></span></span>
      </div>
      <div class="total-row">
        <span>Paid:</span>
        <span>₱ <span id="print-paid"></span></span>
      </div>
      <div class="total-row">
        <span>Change:</span>
        <span>₱ <span id="print-change"></span></span>
      </div>
    </div>

    <!-- Footer -->
    <div class="footer-text">
      Thank you for your purchase!<br>
      Come back soon!
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Simple client-side table sorting (still works on current page)
document.querySelectorAll('#salesTable th.sortable').forEach(header => {
  header.addEventListener('click', () => {
    const table = header.closest('table');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const index = Array.from(header.parentElement.children).indexOf(header);
    const isNumeric = ['subtotal','vat','total','paid','change'].includes(header.dataset.col);
    const isDate = header.dataset.col === 'date';

    const direction = header.classList.contains('asc') ? 'desc' : 'asc';

    table.querySelectorAll('th').forEach(th => {
      th.classList.remove('asc', 'desc');
    });
    header.classList.add(direction);

    rows.sort((a, b) => {
      let aVal = a.children[index].dataset.value || a.children[index].textContent.trim();
      let bVal = b.children[index].dataset.value || b.children[index].textContent.trim();

      if (isNumeric) {
        aVal = parseFloat(aVal) || 0;
        bVal = parseFloat(bVal) || 0;
        return direction === 'asc' ? aVal - bVal : bVal - aVal;
      }
      if (isDate) {
        aVal = parseInt(aVal) || 0;
        bVal = parseInt(bVal) || 0;
        return direction === 'asc' ? aVal - bVal : bVal - aVal;
      }
      return direction === 'asc'
        ? aVal.localeCompare(bVal)
        : bVal.localeCompare(aVal);
    });

    rows.forEach(row => tbody.appendChild(row));
  });
});

// Print functionality (unchanged)
document.querySelectorAll('.print-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const row = btn.closest('tr');
    const saleId = btn.dataset.id;
    const itemsJson = row.getAttribute('data-items');

    let itemsHtml = '<tr><td colspan="4" style="text-align:center; padding:10px 0;">No items found</td></tr>';

    if (itemsJson && itemsJson !== '[]') {
      try {
        const items = JSON.parse(itemsJson);
        if (items && items.length > 0) {
          itemsHtml = '';
          items.forEach(item => {
            const name = item.product_name || 'Unknown Item';
            const qty = Number(item.quantity || 1);
            const price = Number(item.unit_price || 0).toFixed(2);
            const amount = (qty * parseFloat(price)).toFixed(2);

            itemsHtml += `
              <tr style="border-bottom:1px dashed #888;">
                <td style="padding:4px 2px; word-break:break-word;">${name}</td>
                <td class="qty" style="padding:4px 2px; text-align:center;">${qty}</td>
                <td class="price" style="padding:4px 2px; text-align:right;">₱${price}</td>
                <td class="amount" style="padding:4px 2px; text-align:right; font-weight:bold;">₱${amount}</td>
              </tr>
            `;
          });
        }
      } catch (e) {
        itemsHtml = '<tr><td colspan="4" style="text-align:center; padding:10px 0;">Error loading items</td></tr>';
      }
    }

    document.getElementById('print-id').textContent = saleId;
    document.getElementById('print-date').textContent = btn.dataset.date;
    document.getElementById('print-seller').textContent = btn.dataset.seller;
    document.getElementById('print-total').textContent = btn.dataset.total;
    document.getElementById('print-paid').textContent = btn.dataset.paid;
    document.getElementById('print-change').textContent = btn.dataset.change;

    document.getElementById('print-items-body').innerHTML = itemsHtml;

    const printContent = document.getElementById('printTemplate').innerHTML;
    const win = window.open('', '_blank');
    win.document.write(`
      <html>
        <head><title>Sale #${saleId}</title></head>
        <body style="margin:0; padding:0;" onload="window.print(); setTimeout(() => window.close(), 1000);">
          ${printContent}
        </body>
      </html>
    `);
    win.document.close();
  });
});
</script>
</body>
</html>
