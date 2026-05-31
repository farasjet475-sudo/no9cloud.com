<?php
$pageTitle = 'Virtual Stock';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) {
    redirect('dashboard.php');
}

$db = db();
$cid = (int)current_company_id();
$uid = (int)current_user_id();

if (function_exists('require_module_read')) {
    try { require_module_read('virtual_stock'); } catch (Throwable $e) {
        try { require_module_read('stock'); } catch (Throwable $e2) {
            try { require_module_read('inventory'); } catch (Throwable $e3) {}
        }
    }
}

function vs_col_exists(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function vs_table_exists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function vs_can_access_branch_local(int $branchId): bool {
    if ($branchId <= 0) return false;
    if (function_exists('is_company_admin') && is_company_admin()) return true;
    if (function_exists('can_access_branch')) return can_access_branch($branchId);
    return function_exists('current_branch_id') && (int)current_branch_id() === $branchId;
}

function vs_money_qty($v): string {
    return number_format((float)$v, 2);
}

function vs_status_class(float $available, float $physical, float $minStock): string {
    if ($available <= 0) return 'vs-danger';
    if ($minStock > 0 && $available <= $minStock) return 'vs-warning';
    if ($available < $physical) return 'vs-info';
    return 'vs-success';
}

/* -------------------------------------------------------------
   Ensure virtual stock permissions exist automatically
   Compatible with No9 permissions table:
   id, code, module_name, action_name, description, created_at
   Also supports older column names if present.
------------------------------------------------------------- */
if (vs_table_exists($db, 'permissions') && vs_col_exists($db, 'permissions', 'code')) {
    $permissionSeeds = [
        ['virtual_stock.read', 'virtual_stock', 'read', 'View Virtual Stock'],
        ['virtual_stock.write', 'virtual_stock', 'write', 'Manage Virtual Stock'],
        ['virtual_stock.export', 'virtual_stock', 'export', 'Export Virtual Stock'],
    ];

    $hasModuleName = vs_col_exists($db, 'permissions', 'module_name');
    $hasActionName = vs_col_exists($db, 'permissions', 'action_name');
    $hasDescription = vs_col_exists($db, 'permissions', 'description');
    $hasModule = vs_col_exists($db, 'permissions', 'module');
    $hasAction = vs_col_exists($db, 'permissions', 'action');
    $hasName = vs_col_exists($db, 'permissions', 'name');

    foreach ($permissionSeeds as $seed) {
        [$code, $module, $action, $description] = $seed;

        $stmt = $db->prepare("SELECT id FROM permissions WHERE code=? LIMIT 1");
        if (!$stmt) continue;
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) continue;

        if ($hasModuleName && $hasActionName && $hasDescription) {
            $stmt = $db->prepare("INSERT IGNORE INTO permissions (code, module_name, action_name, description) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssss', $code, $module, $action, $description);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($hasModule && $hasAction) {
            $stmt = $db->prepare("INSERT IGNORE INTO permissions (module, action, code) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sss', $module, $action, $code);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($hasModule && $hasName) {
            $stmt = $db->prepare("INSERT IGNORE INTO permissions (module, code, name) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sss', $module, $code, $description);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $db->prepare("INSERT IGNORE INTO permissions (code) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

$isAdmin = function_exists('is_company_admin') && is_company_admin();
$currentBranchId = function_exists('current_branch_id') ? (int)current_branch_id() : 0;
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : ($isAdmin ? 0 : $currentBranchId);
$search = trim($_GET['search'] ?? '');
$stockFilter = trim($_GET['stock_filter'] ?? 'all');

$branches = function_exists('company_branches') ? company_branches() : [];
if (!$isAdmin) {
    $branches = array_values(array_filter($branches, fn($b) => (int)$b['id'] === $currentBranchId));
    $branchFilter = $currentBranchId;
}

/* -------------------------------------------------------------
   Build compatible expressions for optional columns
------------------------------------------------------------- */
$productCols = [
    'sku' => vs_col_exists($db, 'products', 'sku'),
    'barcode' => vs_col_exists($db, 'products', 'barcode'),
    'code' => vs_col_exists($db, 'products', 'code'),
    'category' => vs_col_exists($db, 'products', 'category'),
    'brand' => vs_col_exists($db, 'products', 'brand'),
    'unit' => vs_col_exists($db, 'products', 'unit'),
    'min_stock' => vs_col_exists($db, 'products', 'min_stock'),
    'reorder_level' => vs_col_exists($db, 'products', 'reorder_level'),
    'reserved_stock' => vs_col_exists($db, 'products', 'reserved_stock'),
    'image_path' => vs_col_exists($db, 'products', 'image_path'),
];

$skuExpr = $productCols['sku'] ? "COALESCE(p.sku,'')" : "''";
$barcodeExpr = $productCols['barcode'] ? "COALESCE(p.barcode,'')" : "''";
$codeExpr = $productCols['code'] ? "COALESCE(p.code,'')" : "''";
$categoryExpr = $productCols['category'] ? "COALESCE(p.category,'')" : "''";
$brandExpr = $productCols['brand'] ? "COALESCE(p.brand,'')" : "''";
$unitExpr = $productCols['unit'] ? "COALESCE(p.unit,'pcs')" : "'pcs'";
$minExpr = $productCols['min_stock'] ? "COALESCE(p.min_stock,0)" : ($productCols['reorder_level'] ? "COALESCE(p.reorder_level,0)" : "0");
$reservedExpr = $productCols['reserved_stock'] ? "COALESCE(p.reserved_stock,0)" : "0";
$imageExpr = $productCols['image_path'] ? "COALESCE(p.image_path,'')" : "''";

$where = ["p.company_id = ?"];
$params = [$cid];
$types = 'i';

if ($branchFilter > 0) {
    $where[] = "p.branch_id = ?";
    $params[] = $branchFilter;
    $types .= 'i';
} elseif (!$isAdmin) {
    $where[] = "p.branch_id = ?";
    $params[] = $currentBranchId;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = "(p.name LIKE ? OR $skuExpr LIKE ? OR $barcodeExpr LIKE ? OR $codeExpr LIKE ? OR $categoryExpr LIKE ? OR $brandExpr LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}

$pendingOutSql = vs_table_exists($db, 'stock_transfers') ? "
    SELECT product_id, from_branch_id AS branch_id, company_id, SUM(qty) AS qty
    FROM stock_transfers
    WHERE company_id = ? AND status='pending'
    GROUP BY product_id, from_branch_id, company_id
" : "SELECT 0 product_id, 0 branch_id, 0 company_id, 0 qty WHERE 1=0";

$pendingInSql = vs_table_exists($db, 'stock_transfers') ? "
    SELECT product_id, to_branch_id AS branch_id, company_id, SUM(qty) AS qty
    FROM stock_transfers
    WHERE company_id = ? AND status='pending'
    GROUP BY product_id, to_branch_id, company_id
" : "SELECT 0 product_id, 0 branch_id, 0 company_id, 0 qty WHERE 1=0";

$sql = "
    SELECT
        p.id,
        p.name,
        p.branch_id,
        COALESCE(b.name, 'No Branch') AS branch_name,
        COALESCE(p.stock_qty, 0) AS physical_stock,
        COALESCE(po.qty, 0) AS pending_out,
        COALESCE(pi.qty, 0) AS pending_in,
        $reservedExpr AS reserved_stock,
        $minExpr AS min_stock,
        $skuExpr AS sku,
        $barcodeExpr AS barcode,
        $codeExpr AS code,
        $categoryExpr AS category,
        $brandExpr AS brand,
        $unitExpr AS unit,
        $imageExpr AS image_path,
        (COALESCE(p.stock_qty,0) - COALESCE(po.qty,0) - $reservedExpr) AS available_stock,
        (COALESCE(p.stock_qty,0) + COALESCE(pi.qty,0) - COALESCE(po.qty,0) - $reservedExpr) AS forecast_stock
    FROM products p
    LEFT JOIN branches b ON b.id = p.branch_id
    LEFT JOIN ($pendingOutSql) po ON po.product_id = p.id AND po.branch_id = p.branch_id AND po.company_id = p.company_id
    LEFT JOIN ($pendingInSql) pi ON pi.product_id = p.id AND pi.branch_id = p.branch_id AND pi.company_id = p.company_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.name ASC, p.name ASC
";

$stmt = $db->prepare($sql);
$bindTypes = 'ii' . $types; // pending out company + pending in company + product params
$bindParams = array_merge([$cid, $cid], $params);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$allRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$rows = [];
foreach ($allRows as $r) {
    $available = (float)$r['available_stock'];
    $physical = (float)$r['physical_stock'];
    $minStock = (float)$r['min_stock'];
    if ($stockFilter === 'available' && $available <= 0) continue;
    if ($stockFilter === 'blocked' && ((float)$r['pending_out'] <= 0 && (float)$r['reserved_stock'] <= 0)) continue;
    if ($stockFilter === 'low' && !($minStock > 0 && $available <= $minStock)) continue;
    if ($stockFilter === 'zero' && $available > 0) continue;
    $rows[] = $r;
}

$totalPhysical = 0; $totalPendingOut = 0; $totalPendingIn = 0; $totalReserved = 0; $totalAvailable = 0; $lowCount = 0; $blockedCount = 0; $zeroCount = 0;
foreach ($rows as $r) {
    $totalPhysical += (float)$r['physical_stock'];
    $totalPendingOut += (float)$r['pending_out'];
    $totalPendingIn += (float)$r['pending_in'];
    $totalReserved += (float)$r['reserved_stock'];
    $totalAvailable += (float)$r['available_stock'];
    if ((float)$r['min_stock'] > 0 && (float)$r['available_stock'] <= (float)$r['min_stock']) $lowCount++;
    if ((float)$r['pending_out'] > 0 || (float)$r['reserved_stock'] > 0) $blockedCount++;
    if ((float)$r['available_stock'] <= 0) $zeroCount++;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=virtual_stock_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product', 'SKU', 'Branch', 'Physical Stock', 'Pending Out', 'Pending In', 'Reserved', 'Available', 'Forecast', 'Min Stock', 'Unit']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['name'], $r['sku'], $r['branch_name'], $r['physical_stock'], $r['pending_out'], $r['pending_in'], $r['reserved_stock'], $r['available_stock'], $r['forecast_stock'], $r['min_stock'], $r['unit']]);
    }
    fclose($out);
    exit;
}
?>

<style>
.virtual-stock-page{--vs-dark:#0f172a;--vs-muted:#64748b;--vs-border:#e5e7eb;--vs-bg:#f6f8fb;--vs-blue:#2563eb;--vs-green:#10b981;--vs-orange:#f59e0b;--vs-red:#ef4444;display:grid;gap:18px}.vs-hero{border-radius:28px;padding:28px;color:#fff;background:linear-gradient(135deg,#0f172a,#1d4ed8 58%,#0f766e);box-shadow:0 18px 46px rgba(15,23,42,.17);position:relative;overflow:hidden}.vs-hero:after{content:"";position:absolute;right:-70px;top:-70px;width:210px;height:210px;border-radius:999px;background:rgba(255,255,255,.10)}.vs-hero h3{margin:0;font-weight:900;letter-spacing:-.03em}.vs-hero p{margin:8px 0 0;color:rgba(255,255,255,.82)}.vs-card{border:1px solid var(--vs-border);border-radius:24px;background:#fff;box-shadow:0 14px 34px rgba(15,23,42,.06)}.vs-card-body{padding:22px}.vs-kpi{border-radius:22px;padding:18px;color:#fff;height:100%;box-shadow:0 12px 26px rgba(15,23,42,.11);position:relative;overflow:hidden}.vs-kpi:after{content:"";position:absolute;right:-34px;top:-34px;width:92px;height:92px;background:rgba(255,255,255,.14);border-radius:50%}.vs-kpi small{font-size:12px;text-transform:uppercase;letter-spacing:.04em;font-weight:800;opacity:.9}.vs-kpi h3{margin:8px 0 0;font-size:1.7rem;font-weight:900}.vs-kpi.blue{background:linear-gradient(135deg,#2563eb,#1d4ed8)}.vs-kpi.orange{background:linear-gradient(135deg,#f59e0b,#d97706)}.vs-kpi.green{background:linear-gradient(135deg,#10b981,#059669)}.vs-kpi.red{background:linear-gradient(135deg,#ef4444,#dc2626)}.vs-kpi.purple{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}.vs-title{font-weight:900;color:#0f172a;margin:0}.vs-sub{color:var(--vs-muted);font-size:13px}.vs-form .form-control,.vs-form .form-select{border-radius:15px;min-height:48px;border-color:#dbe3ef}.vs-form .btn,.virtual-stock-page .btn{border-radius:14px;font-weight:800}.vs-table-wrap{border:1px solid #e2e8f0;border-radius:20px;overflow:hidden}.vs-table thead th{background:#f8fafc;color:#334155;font-size:12px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}.vs-table td{vertical-align:middle;font-size:13px}.vs-product{display:flex;align-items:center;gap:10px}.vs-img{width:38px;height:38px;border-radius:12px;object-fit:cover;background:#eff6ff;border:1px solid #dbeafe}.vs-icon{width:38px;height:38px;border-radius:12px;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-weight:900}.vs-badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900}.vs-success{background:#dcfce7;color:#166534}.vs-warning{background:#fef3c7;color:#92400e}.vs-danger{background:#fee2e2;color:#991b1b}.vs-info{background:#dbeafe;color:#1d4ed8}.vs-muted-pill{background:#f1f5f9;color:#475569}.vs-qty{font-weight:900;color:#0f172a}.vs-negative{color:#b91c1c}.vs-empty{padding:46px 16px;text-align:center;color:#64748b;background:#f8fafc}.vs-legend{display:flex;gap:8px;flex-wrap:wrap}.vs-legend .vs-badge{font-size:11px}.vs-help{border-radius:18px;background:linear-gradient(180deg,#f8fafc,#fff);border:1px dashed #cbd5e1;padding:16px;color:#475569}.vs-help strong{color:#0f172a}@media(max-width:768px){.vs-hero{padding:22px}.vs-card-body{padding:18px}}
</style>

<div class="container-fluid py-4 virtual-stock-page">
    <div class="vs-hero">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative">
            <div>
                <h3>Virtual Stock Control</h3>
                <p>Physical stock, pending transfers, reserved stock, and available stock in one SaaS-ready inventory view.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="virtual_stock.php?<?php echo http_build_query(array_merge($_GET, ['export'=>'csv'])); ?>" class="btn btn-light"><i class="bi bi-download me-1"></i> Export CSV</a>
                <a href="stock_transfer.php" class="btn btn-outline-light"><i class="bi bi-arrow-left-right me-1"></i> Transfer</a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-2 col-6"><div class="vs-kpi blue"><small>Products</small><h3><?php echo number_format(count($rows)); ?></h3></div></div>
        <div class="col-md-2 col-6"><div class="vs-kpi green"><small>Physical</small><h3><?php echo vs_money_qty($totalPhysical); ?></h3></div></div>
        <div class="col-md-2 col-6"><div class="vs-kpi orange"><small>Pending Out</small><h3><?php echo vs_money_qty($totalPendingOut); ?></h3></div></div>
        <div class="col-md-2 col-6"><div class="vs-kpi purple"><small>Pending In</small><h3><?php echo vs_money_qty($totalPendingIn); ?></h3></div></div>
        <div class="col-md-2 col-6"><div class="vs-kpi red"><small>Blocked</small><h3><?php echo number_format($blockedCount); ?></h3></div></div>
        <div class="col-md-2 col-6"><div class="vs-kpi blue"><small>Available</small><h3><?php echo vs_money_qty($totalAvailable); ?></h3></div></div>
    </div>

    <div class="vs-card">
        <div class="vs-card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <h5 class="vs-title">Search & Filters</h5>
                    <div class="vs-sub">Available = Physical - Pending Out - Reserved</div>
                </div>
                <div class="vs-legend">
                    <span class="vs-badge vs-success">Available</span>
                    <span class="vs-badge vs-info">Pending</span>
                    <span class="vs-badge vs-warning">Low</span>
                    <span class="vs-badge vs-danger">Zero</span>
                </div>
            </div>

            <form class="row g-3 vs-form" method="get">
                <div class="col-lg-4"><input type="text" name="search" class="form-control" placeholder="Search product, SKU, barcode, category..." value="<?php echo e($search); ?>"></div>
                <div class="col-lg-3">
                    <select name="branch_id" class="form-select" <?php echo !$isAdmin ? 'disabled' : ''; ?>>
                        <?php if ($isAdmin): ?><option value="0">All Branches</option><?php endif; ?>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>" <?php echo $branchFilter === (int)$b['id'] ? 'selected' : ''; ?>><?php echo e($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$isAdmin): ?><input type="hidden" name="branch_id" value="<?php echo (int)$currentBranchId; ?>"><?php endif; ?>
                </div>
                <div class="col-lg-3">
                    <select name="stock_filter" class="form-select">
                        <option value="all" <?php echo $stockFilter==='all'?'selected':''; ?>>All Stock</option>
                        <option value="available" <?php echo $stockFilter==='available'?'selected':''; ?>>Available Only</option>
                        <option value="blocked" <?php echo $stockFilter==='blocked'?'selected':''; ?>>Blocked / Pending Only</option>
                        <option value="low" <?php echo $stockFilter==='low'?'selected':''; ?>>Low Stock</option>
                        <option value="zero" <?php echo $stockFilter==='zero'?'selected':''; ?>>Zero Available</option>
                    </select>
                </div>
                <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-fill"><i class="bi bi-search"></i></button><a href="virtual_stock.php" class="btn btn-outline-secondary">Reset</a></div>
            </form>
        </div>
    </div>

    <div class="vs-card">
        <div class="vs-card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="vs-title">Virtual Stock List</h5>
                    <div class="vs-sub">Prevents overselling and double-transfer by showing only safe available stock.</div>
                </div>
                <div class="vs-help small">
                    <strong>Forecast</strong> = Physical + Pending In - Pending Out - Reserved
                </div>
            </div>

            <div class="vs-table-wrap">
                <div class="table-responsive">
                    <table class="table vs-table align-middle mb-0" id="virtualStockTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Branch</th>
                                <th>Physical</th>
                                <th>Pending Out</th>
                                <th>Pending In</th>
                                <th>Reserved</th>
                                <th>Available</th>
                                <th>Forecast</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $available = (float)$r['available_stock'];
                                $physical = (float)$r['physical_stock'];
                                $minStock = (float)$r['min_stock'];
                                $class = vs_status_class($available, $physical, $minStock);
                                $label = 'Available';
                                if ($available <= 0) $label = 'Zero Available';
                                elseif ($minStock > 0 && $available <= $minStock) $label = 'Low Stock';
                                elseif ((float)$r['pending_out'] > 0 || (float)$r['reserved_stock'] > 0) $label = 'Partly Blocked';
                            ?>
                            <tr>
                                <td>
                                    <div class="vs-product">
                                        <?php if (!empty($r['image_path'])): ?><img src="<?php echo e($r['image_path']); ?>" class="vs-img" alt=""><?php else: ?><div class="vs-icon"><i class="bi bi-box-seam"></i></div><?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?php echo e($r['name']); ?></div>
                                            <div class="vs-sub">
                                                <?php echo $r['sku'] ? 'SKU: '.e($r['sku']).' · ' : ''; ?>
                                                <?php echo $r['barcode'] ? 'Barcode: '.e($r['barcode']).' · ' : ''; ?>
                                                <?php echo e($r['category'] ?: $r['brand'] ?: 'No category'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="vs-badge vs-muted-pill"><?php echo e($r['branch_name']); ?></span></td>
                                <td class="vs-qty"><?php echo vs_money_qty($r['physical_stock']); ?> <?php echo e($r['unit']); ?></td>
                                <td class="vs-qty <?php echo (float)$r['pending_out']>0?'vs-negative':''; ?>"><?php echo vs_money_qty($r['pending_out']); ?></td>
                                <td class="vs-qty"><?php echo vs_money_qty($r['pending_in']); ?></td>
                                <td class="vs-qty <?php echo (float)$r['reserved_stock']>0?'vs-negative':''; ?>"><?php echo vs_money_qty($r['reserved_stock']); ?></td>
                                <td class="vs-qty <?php echo $available<=0?'vs-negative':''; ?>"><?php echo vs_money_qty($available); ?></td>
                                <td class="vs-qty"><?php echo vs_money_qty($r['forecast_stock']); ?></td>
                                <td><span class="vs-badge <?php echo $class; ?>"><?php echo e($label); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="9"><div class="vs-empty">No virtual stock records found.</div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
