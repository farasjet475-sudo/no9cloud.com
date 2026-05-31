<?php
$pageTitle = 'Stock Transfer';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) {
    redirect('dashboard.php');
}

/*
|--------------------------------------------------------------------------
| STOCK TRANSFER ACCESS
|--------------------------------------------------------------------------
| Any normal company user can create a transfer request from his/her own branch.
| Approval must be done by another user (creator cannot approve own request).
|--------------------------------------------------------------------------
*/

$cid = current_company_id();
$db  = db();
$error = '';
$message = '';

$currentUserId      = function_exists('current_user_id') ? (int)current_user_id() : (int)(current_user()['id'] ?? 0);
$currentBranchId    = function_exists('current_branch_id') ? (int)current_branch_id() : (int)(current_user()['branch_id'] ?? 0);
$canManageBranches  = function_exists('is_company_admin') && is_company_admin();

function st_col_exists($db, $table, $column) {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function st_table_exists($db, $table) {
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function st_badge_class($status) {
    if ($status === 'approved') return 'bg-success-subtle text-success';
    if ($status === 'completed') return 'bg-success-subtle text-success';
    if ($status === 'cancelled') return 'bg-danger-subtle text-danger';
    return 'bg-warning-subtle text-warning';
}

function st_safe_product_value($arr, $key, $default = '') {
    return array_key_exists($key, $arr) ? $arr[$key] : $default;
}

function st_branch_exists_in_company($branches, $branchId) {
    foreach ($branches as $b) {
        if ((int)$b['id'] === (int)$branchId) return true;
    }
    return false;
}

/* ========================
   ENSURE TABLE EXISTS
======================== */
$db->query("
CREATE TABLE IF NOT EXISTS stock_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transfer_no VARCHAR(50) NULL,
    company_id INT NOT NULL,
    from_branch_id INT NOT NULL,
    to_branch_id INT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    cancelled_by INT NULL,
    cancelled_at DATETIME NULL,
    cancel_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

if (!st_col_exists($db, 'stock_transfers', 'transfer_no')) {
    $db->query("ALTER TABLE stock_transfers ADD COLUMN transfer_no VARCHAR(50) NULL AFTER id");
}

if (!st_col_exists($db, 'stock_transfers', 'cancelled_by')) {
    $db->query("ALTER TABLE stock_transfers ADD COLUMN cancelled_by INT NULL");
}

if (!st_col_exists($db, 'stock_transfers', 'cancelled_at')) {
    $db->query("ALTER TABLE stock_transfers ADD COLUMN cancelled_at DATETIME NULL");
}

if (!st_col_exists($db, 'stock_transfers', 'cancel_reason')) {
    $db->query("ALTER TABLE stock_transfers ADD COLUMN cancel_reason TEXT NULL");
}

/* fix old completed status */
$db->query("
    UPDATE stock_transfers 
    SET status='approved' 
    WHERE company_id=$cid AND status='completed'
");

/* generate missing transfer no */
$missingNos = $db->query("
    SELECT id 
    FROM stock_transfers 
    WHERE company_id=$cid 
    AND (transfer_no IS NULL OR transfer_no='')
");

while ($m = $missingNos->fetch_assoc()) {
    $tid = (int)$m['id'];
    $no = 'TRF-' . str_pad($tid, 5, '0', STR_PAD_LEFT);
    $stmt = $db->prepare("UPDATE stock_transfers SET transfer_no=? WHERE id=? AND company_id=?");
    $stmt->bind_param('sii', $no, $tid, $cid);
    $stmt->execute();
    $stmt->close();
}

$allCompanyBranches = function_exists('company_branches') ? company_branches() : [];
$sourceBranches = $canManageBranches
    ? $allCompanyBranches
    : array_values(array_filter($allCompanyBranches, function ($b) use ($currentBranchId) {
        return (int)$b['id'] === (int)$currentBranchId;
    }));

/* ========================
   POST ACTIONS
======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    /* ========================
       CREATE TRANSFER
    ========================= */
    if ($action === 'create_transfer') {
        $from  = (int)($_POST['from_branch_id'] ?? 0);
        $to    = (int)($_POST['to_branch_id'] ?? 0);
        $pid   = (int)($_POST['product_id'] ?? 0);
        $qty   = (float)($_POST['qty'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($from <= 0 || $to <= 0 || $pid <= 0) {
            $error = 'Please select source branch, destination branch, and product.';
        } elseif (!st_branch_exists_in_company($allCompanyBranches, $from) || !st_branch_exists_in_company($allCompanyBranches, $to)) {
            $error = 'Invalid branch selected.';
        } elseif (!$canManageBranches && $from !== $currentBranchId) {
            $error = 'You can create transfer only from your own branch.';
        } elseif ($from === $to) {
            $error = 'Source and destination branch cannot be the same.';
        } elseif ($qty <= 0) {
            $error = 'Quantity must be greater than zero.';
        } else {
            $stmt = $db->prepare("
                SELECT *
                FROM products
                WHERE id=? AND company_id=? AND branch_id=?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $pid, $cid, $from);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                $error = 'Selected product not found in source branch.';
            } elseif ((float)$product['stock_qty'] < $qty) {
                $error = 'Not enough stock in source branch.';
            } else {
                $createdBy = $currentUserId;

                $stmt = $db->prepare("
                    INSERT INTO stock_transfers
                    (transfer_no, company_id, from_branch_id, to_branch_id, product_id, qty, notes, status, created_by)
                    VALUES ('', ?, ?, ?, ?, ?, ?, 'pending', ?)
                ");
                $stmt->bind_param('iiiidsi', $cid, $from, $to, $pid, $qty, $notes, $createdBy);
                $stmt->execute();
                $newId = $stmt->insert_id;
                $stmt->close();

                $transferNo = 'TRF-' . str_pad($newId, 5, '0', STR_PAD_LEFT);

                $stmt = $db->prepare("
                    UPDATE stock_transfers 
                    SET transfer_no=? 
                    WHERE id=? AND company_id=?
                ");
                $stmt->bind_param('sii', $transferNo, $newId, $cid);
                $stmt->execute();
                $stmt->close();

                $message = 'Transfer request created successfully.';
            }
        }
    }

    /* ========================
       APPROVE TRANSFER
    ========================= */
    if ($action === 'approve_transfer') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $db->prepare("
            SELECT *
            FROM stock_transfers
            WHERE id=? AND company_id=? AND status='pending'
            LIMIT 1
        ");
        $stmt->bind_param('ii', $id, $cid);
        $stmt->execute();
        $transfer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$transfer) {
            $error = 'Pending transfer not found.';
        } elseif ((int)($transfer['created_by'] ?? 0) === $currentUserId) {
            $error = 'You cannot approve your own transfer. Another user must approve it.';
        } else {
            $from = (int)$transfer['from_branch_id'];
            $to   = (int)$transfer['to_branch_id'];
            $pid  = (int)$transfer['product_id'];
            $qty  = (float)$transfer['qty'];

            $stmt = $db->prepare("
                SELECT *
                FROM products
                WHERE id=? AND company_id=? AND branch_id=?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $pid, $cid, $from);
            $stmt->execute();
            $sourceProduct = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sourceProduct) {
                $error = 'Source product not found.';
            } elseif ((float)$sourceProduct['stock_qty'] < $qty) {
                $error = 'Not enough stock to approve this transfer.';
            } else {
                $db->begin_transaction();

                try {
                    $approvedBy  = $currentUserId;
                    $referenceNo = !empty($transfer['transfer_no'])
                        ? $transfer['transfer_no']
                        : 'TRF-' . str_pad($id, 5, '0', STR_PAD_LEFT);

                    $name        = (string)st_safe_product_value($sourceProduct, 'name', '');
                    $sku         = (string)st_safe_product_value($sourceProduct, 'sku', '');
                    $barcode     = (string)st_safe_product_value($sourceProduct, 'barcode', '');
                    $code        = (string)st_safe_product_value($sourceProduct, 'code', '');
                    $brand       = (string)st_safe_product_value($sourceProduct, 'brand', '');
                    $category    = (string)st_safe_product_value($sourceProduct, 'category', '');
                    $price       = (float)st_safe_product_value($sourceProduct, 'price', 0);
                    $costPrice   = (float)st_safe_product_value($sourceProduct, 'cost_price', 0);
                    $minStock    = (float)st_safe_product_value($sourceProduct, 'min_stock', 0);
                    $unit        = (string)st_safe_product_value($sourceProduct, 'unit', 'pcs');
                    $description = (string)st_safe_product_value($sourceProduct, 'description', '');
                    $imagePath   = (string)st_safe_product_value($sourceProduct, 'image_path', '');
                    $reorder     = (float)st_safe_product_value($sourceProduct, 'reorder_level', 0);

                    $stmt = $db->prepare("
                        UPDATE products
                        SET stock_qty = stock_qty - ?
                        WHERE id=? AND company_id=? AND branch_id=? AND stock_qty >= ?
                    ");
                    $stmt->bind_param('diiid', $qty, $pid, $cid, $from, $qty);
                    $stmt->execute();

                    if ($stmt->affected_rows <= 0) {
                        $stmt->close();
                        throw new Exception('Failed to deduct source stock.');
                    }
                    $stmt->close();

                    $stmt = $db->prepare("
                        SELECT id
                        FROM products
                        WHERE company_id=? 
                        AND branch_id=?
                        AND (
                            (sku <> '' AND sku=?) OR
                            (barcode <> '' AND barcode=?) OR
                            (code <> '' AND code=?) OR
                            name=?
                        )
                        LIMIT 1
                    ");
                    $stmt->bind_param('iissss', $cid, $to, $sku, $barcode, $code, $name);
                    $stmt->execute();
                    $dest = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($dest) {
                        $destProductId = (int)$dest['id'];

                        $stmt = $db->prepare("
                            UPDATE products
                            SET stock_qty = stock_qty + ?
                            WHERE id=? AND company_id=? AND branch_id=?
                        ");
                        $stmt->bind_param('diii', $qty, $destProductId, $cid, $to);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO products
                            (
                                company_id, branch_id, name, sku, category,
                                price, cost_price, stock_qty, min_stock, unit,
                                description, image_path, barcode, code, brand,
                                reorder_level, created_at
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->bind_param(
                            'iisssddddssssssd',
                            $cid,
                            $to,
                            $name,
                            $sku,
                            $category,
                            $price,
                            $costPrice,
                            $qty,
                            $minStock,
                            $unit,
                            $description,
                            $imagePath,
                            $barcode,
                            $code,
                            $brand,
                            $reorder
                        );
                        $stmt->execute();
                        $destProductId = $stmt->insert_id;
                        $stmt->close();
                    }

                    if (st_table_exists($db, 'stock_movements')) {
                        $noteOut = 'Transfer OUT to branch #' . $to;
                        $noteIn  = 'Transfer IN from branch #' . $from;

                        $stmt = $db->prepare("
                            INSERT INTO stock_movements
                            (
                                company_id, branch_id, product_id, transaction_type,
                                reference_no, qty_in, qty_out, unit_cost, notes, created_by
                            )
                            VALUES (?, ?, ?, 'TRANSFER_OUT', ?, 0, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param(
                            'iiisddsi',
                            $cid,
                            $from,
                            $pid,
                            $referenceNo,
                            $qty,
                            $costPrice,
                            $noteOut,
                            $approvedBy
                        );
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $db->prepare("
                            INSERT INTO stock_movements
                            (
                                company_id, branch_id, product_id, transaction_type,
                                reference_no, qty_in, qty_out, unit_cost, notes, created_by
                            )
                            VALUES (?, ?, ?, 'TRANSFER_IN', ?, ?, 0, ?, ?, ?)
                        ");
                        $stmt->bind_param(
                            'iiisddsi',
                            $cid,
                            $to,
                            $destProductId,
                            $referenceNo,
                            $qty,
                            $costPrice,
                            $noteIn,
                            $approvedBy
                        );
                        $stmt->execute();
                        $stmt->close();
                    }

                    $stmt = $db->prepare("
                        UPDATE stock_transfers
                        SET status='approved', approved_by=?, approved_at=NOW()
                        WHERE id=? AND company_id=? AND status='pending'
                    ");
                    $stmt->bind_param('iii', $approvedBy, $id, $cid);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();
                    $message = 'Transfer approved successfully.';
                } catch (Throwable $e) {
                    $db->rollback();
                    $error = 'Approve failed: ' . $e->getMessage();
                }
            }
        }
    }

    /* ========================
       CANCEL TRANSFER
    ========================= */
    if ($action === 'cancel_transfer') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['cancel_reason'] ?? 'Cancelled by admin');

        $stmt = $db->prepare("
            UPDATE stock_transfers
            SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?
            WHERE id=? AND company_id=? AND status='pending'
        ");
        $cancelledBy = $currentUserId;
        $stmt->bind_param('isii', $cancelledBy, $reason, $id, $cid);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();

        if ($ok) {
            $message = 'Transfer cancelled successfully.';
        } else {
            $error = 'Pending transfer not found or already processed.';
        }
    }
}

/* ========================
   LOOKUPS
======================== */
$branches = $allCompanyBranches;
$productBranchSql = $canManageBranches ? '' : ' AND p.branch_id = ' . (int)$currentBranchId;

$products = $db->query("
    SELECT 
        p.id, 
        p.name, 
        p.stock_qty, 
        p.branch_id, 
        COALESCE(p.sku, '') AS sku,
        COALESCE(p.barcode, '') AS barcode,
        COALESCE(b.name, 'No Branch') AS branch_name
    FROM products p
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE p.company_id = $cid
    $productBranchSql
    ORDER BY b.name ASC, p.name ASC
")->fetch_all(MYSQLI_ASSOC);

$rows = $db->query("
    SELECT
        t.*,
        p.name AS product_name,
        fb.name AS from_branch_name,
        tb.name AS to_branch_name,
        cu.full_name AS created_by_name,
        au.full_name AS approved_by_name,
        xu.full_name AS cancelled_by_name
    FROM stock_transfers t
    LEFT JOIN products p ON p.id = t.product_id
    LEFT JOIN branches fb ON fb.id = t.from_branch_id
    LEFT JOIN branches tb ON tb.id = t.to_branch_id
    LEFT JOIN users cu ON cu.id = t.created_by
    LEFT JOIN users au ON au.id = t.approved_by
    LEFT JOIN users xu ON xu.id = t.cancelled_by
    WHERE t.company_id = $cid
    ORDER BY t.id DESC
")->fetch_all(MYSQLI_ASSOC);

$totalTransfers = count($rows);
$pendingCount = 0;
$approvedCount = 0;
$cancelledCount = 0;

foreach ($rows as $r) {
    if ($r['status'] === 'pending') {
        $pendingCount++;
    } elseif ($r['status'] === 'approved' || $r['status'] === 'completed') {
        $approvedCount++;
    } elseif ($r['status'] === 'cancelled') {
        $cancelledCount++;
    }
}
?>

<style>
:root{
    --st-bg:#f6f8fb;
    --st-card:#ffffff;
    --st-border:#e5e7eb;
    --st-text:#0f172a;
    --st-muted:#64748b;
    --st-primary:#2563eb;
    --st-primary-dark:#1d4ed8;
}
.transfer-shell{display:grid;gap:18px;background:var(--st-bg);min-height:calc(100vh - 80px)}
.hero-card{
    border:0;
    border-radius:24px;
    background:linear-gradient(135deg,#0f172a,#1e293b 55%,#2563eb);
    color:#fff;
    box-shadow:0 18px 42px rgba(15,23,42,.18);
    padding:26px;
}
.hero-card h3{margin:0;font-weight:900;letter-spacing:-.02em}
.hero-card p{margin:7px 0 0;color:rgba(255,255,255,.84)}
.soft-card{
    border:1px solid rgba(226,232,240,.85);
    border-radius:22px;
    background:var(--st-card);
    box-shadow:0 14px 34px rgba(15,23,42,.07);
}
.kpi-card{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
    position:relative;
    overflow:hidden;
}
.kpi-card:after{
    content:"";
    position:absolute;
    right:-28px;
    top:-28px;
    width:86px;
    height:86px;
    border-radius:999px;
    background:rgba(255,255,255,.16);
}
.kpi-card small{opacity:.92;font-size:.84rem;font-weight:700}
.kpi-card h3{margin:8px 0 0;font-size:1.75rem;font-weight:900}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#f59e0b,#d97706)}
.kpi-3{background:linear-gradient(135deg,#10b981,#059669)}
.kpi-4{background:linear-gradient(135deg,#ef4444,#dc2626)}
.section-title{font-weight:900;margin-bottom:6px;color:var(--st-text);letter-spacing:-.01em}
.section-sub{color:var(--st-muted);font-size:14px}
.form-label{font-weight:800;color:#334155}
.form-control,.form-select{
    border-radius:14px;
    border-color:var(--st-border);
    min-height:44px;
}
.form-control:focus,.form-select:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 .22rem rgba(37,99,235,.12);
}
.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
    background:#fff;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.035em;
}
.table td{
    font-size:13px;
    vertical-align:middle;
}
.status-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
}
.meta-text{font-size:12px;color:#64748b}
.product-help{font-size:12px;color:#64748b;margin-top:6px}
.product-search-box{position:relative}
.product-results{
    position:absolute;
    z-index:1050;
    top:calc(100% + 6px);
    left:0;
    right:0;
    max-height:280px;
    overflow:auto;
    background:#fff;
    border:1px solid #dbe3ef;
    border-radius:16px;
    box-shadow:0 20px 42px rgba(15,23,42,.16);
    display:none;
}
.product-results.show{display:block}
.product-option{
    padding:12px 14px;
    cursor:pointer;
    border-bottom:1px solid #f1f5f9;
}
.product-option:last-child{border-bottom:0}
.product-option:hover,.product-option.active{background:#eff6ff}
.product-option-title{
    font-weight:900;
    color:#0f172a;
    display:flex;
    justify-content:space-between;
    gap:10px;
}
.product-option-meta{
    margin-top:4px;
    color:#64748b;
    font-size:12px;
}
.product-stock{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:3px 8px;
    background:#ecfdf5;
    color:#047857;
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}
.search-toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:space-between;
    margin-bottom:14px;
}
.search-toolbar .form-control{max-width:320px}
.empty-state{
    padding:44px 16px;
    text-align:center;
    color:#64748b;
}
.btn{
    border-radius:12px;
    font-weight:800;
}
</style>

<div class="container-fluid py-4 transfer-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Stock Transfer Pro</h3>
                <p>Create pending requests, approve safely, and move stock between branches with full history.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Transfers</small>
                <h3><?php echo $totalTransfers; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Pending</small>
                <h3><?php echo $pendingCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Approved</small>
                <h3><?php echo $approvedCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Cancelled</small>
                <h3><?php echo $cancelledCount; ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-4">
            <div class="soft-card p-4">
                <div class="section-title">Create Transfer Request</div>
                <div class="section-sub mb-3">Each user can request from their own branch. Another user must approve before stock moves.</div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_transfer">

                    <div class="mb-3">
                        <label class="form-label">From Branch</label>
                        <select name="from_branch_id" class="form-select" required>
                            <option value="">Select source branch</option>
                            <?php foreach ($sourceBranches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>" <?php echo (!$canManageBranches && (int)$b['id'] === $currentBranchId) ? 'selected' : ''; ?>>
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">To Branch</label>
                        <select name="to_branch_id" class="form-select" required>
                            <option value="">Select destination branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>">
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Product Text Search</label>
                        <div class="product-search-box">
                            <input
                                type="text"
                                id="productSearch"
                                class="form-control"
                                autocomplete="off"
                                placeholder="Search product by name, SKU, barcode, or code"
                                disabled
                            >
                            <input type="hidden" name="product_id" id="productId" required>
                            <div id="productResults" class="product-results"></div>
                        </div>
                        <div id="selectedProductInfo" class="product-help">
                            First select source branch, then search product.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" step="0.01" min="0.01" name="qty" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Reason or note"></textarea>
                    </div>

                    <button class="btn btn-primary w-100">Create Request</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="soft-card p-4">
                <div class="section-title">Transfer History</div>
                <div class="section-sub mb-3">Only pending requests can be approved or cancelled.</div>

                <div class="search-toolbar">
                    <input type="text" id="transferTableSearch" class="form-control" placeholder="Search transfer no, product, branch, status...">
                    <div class="section-sub align-self-center">
                        Showing <?php echo number_format($totalTransfers); ?> transfer records
                    </div>
                </div>

                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="transferTable">
                            <thead>
                                <tr>
                                    <th>Transfer No</th>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>By</th>
                                    <th>Notes</th>
                                    <th width="210">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?php
                                                echo e(
                                                    !empty($r['transfer_no'])
                                                        ? $r['transfer_no']
                                                        : 'TRF-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT)
                                                );
                                                ?>
                                            </strong>
                                        </td>
                                        <td><?php echo e($r['created_at']); ?></td>
                                        <td><?php echo e($r['product_name'] ?? '-'); ?></td>
                                        <td><?php echo e($r['from_branch_name'] ?? '-'); ?></td>
                                        <td><?php echo e($r['to_branch_name'] ?? '-'); ?></td>
                                        <td><?php echo number_format((float)$r['qty'], 2); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo st_badge_class($r['status']); ?>">
                                                <?php echo e(ucfirst($r['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo e($r['created_by_name'] ?? '-'); ?></div>

                                            <?php if (!empty($r['approved_by_name'])): ?>
                                                <div class="meta-text">
                                                    Approved: <?php echo e($r['approved_by_name']); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($r['cancelled_by_name'])): ?>
                                                <div class="meta-text">
                                                    Cancelled: <?php echo e($r['cancelled_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo e($r['notes'] ?? ''); ?>

                                            <?php if (!empty($r['cancel_reason'])): ?>
                                                <div class="meta-text">
                                                    Reason: <?php echo e($r['cancel_reason']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($r['status'] === 'pending'): ?>
                                                <?php if ((int)($r['created_by'] ?? 0) !== $currentUserId): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Approve this transfer? Stock will move now.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                        <input type="hidden" name="action" value="approve_transfer">
                                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                        <button class="btn btn-sm btn-success">Approve</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted small">Waiting for another user</span>
                                                <?php endif; ?>

                                                <form method="post" class="d-inline" onsubmit="return confirm('Cancel this transfer request?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="cancel_transfer">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <input type="hidden" name="cancel_reason" value="Cancelled by admin">
                                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">Completed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (!$rows): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-5">
                                            No transfer records found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const fromBranch = document.querySelector('[name="from_branch_id"]');
    const toBranch = document.querySelector('[name="to_branch_id"]');
    const productSearch = document.getElementById('productSearch');
    const productId = document.getElementById('productId');
    const productResults = document.getElementById('productResults');
    const selectedProductInfo = document.getElementById('selectedProductInfo');
    const transferTableSearch = document.getElementById('transferTableSearch');
    const transferTable = document.getElementById('transferTable');

    const products = <?php echo json_encode(array_map(function ($p) {
        return [
            'id' => (int)$p['id'],
            'name' => (string)$p['name'],
            'branch_id' => (int)$p['branch_id'],
            'branch_name' => (string)$p['branch_name'],
            'stock_qty' => (float)$p['stock_qty'],
            'sku' => (string)$p['sku'],
            'barcode' => (string)$p['barcode'],
        ];
    }, $products), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function formatStock(value) {
        return Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function clearProductSelection(message) {
        productId.value = '';
        productSearch.value = '';
        productResults.innerHTML = '';
        productResults.classList.remove('show');
        selectedProductInfo.textContent = message || 'Search product by name, SKU, barcode, or code.';
    }

    function getBranchProducts() {
        const branchId = parseInt(fromBranch.value || '0', 10);
        if (!branchId) return [];
        return products.filter(p => parseInt(p.branch_id, 10) === branchId);
    }

    function renderProductResults() {
        const branchId = fromBranch.value;
        const term = productSearch.value.trim().toLowerCase();

        productId.value = '';

        if (!branchId) {
            productResults.innerHTML = '<div class="product-option"><div class="product-option-title">Select source branch first</div></div>';
            productResults.classList.add('show');
            return;
        }

        let list = getBranchProducts();

        if (term) {
            list = list.filter(p => {
                const haystack = [
                    p.name || '',
                    p.sku || '',
                    p.barcode || '',
                    p.branch_name || ''
                ].join(' ').toLowerCase();

                return haystack.includes(term);
            });
        }

        list = list.slice(0, 30);

        if (!list.length) {
            productResults.innerHTML = '<div class="product-option"><div class="product-option-title">No product found</div><div class="product-option-meta">Try another name, SKU, or barcode.</div></div>';
            productResults.classList.add('show');
            return;
        }

        productResults.innerHTML = list.map(p => `
            <div class="product-option" data-id="${p.id}">
                <div class="product-option-title">
                    <span>${escapeHtml(p.name)}</span>
                    <span class="product-stock">Stock: ${formatStock(p.stock_qty)}</span>
                </div>
                <div class="product-option-meta">
                    Branch: ${escapeHtml(p.branch_name || '-')}
                    ${p.sku ? ' • SKU: ' + escapeHtml(p.sku) : ''}
                    ${p.barcode ? ' • Barcode: ' + escapeHtml(p.barcode) : ''}
                </div>
            </div>
        `).join('');

        productResults.classList.add('show');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (m) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[m];
        });
    }

    function selectProduct(id) {
        const product = products.find(p => parseInt(p.id, 10) === parseInt(id, 10));
        if (!product) return;

        productId.value = product.id;
        productSearch.value = product.name;
        selectedProductInfo.innerHTML =
            'Selected: <strong>' + escapeHtml(product.name) + '</strong>' +
            ' • Stock: <strong>' + formatStock(product.stock_qty) + '</strong>' +
            (product.sku ? ' • SKU: ' + escapeHtml(product.sku) : '') +
            (product.barcode ? ' • Barcode: ' + escapeHtml(product.barcode) : '');

        productResults.classList.remove('show');
    }

    function syncSourceBranch() {
        const branchId = fromBranch.value;
        productSearch.disabled = !branchId;

        clearProductSelection(
            branchId
                ? 'Search product by name, SKU, barcode, or code.'
                : 'First select source branch, then search product.'
        );

        if (branchId) {
            const count = getBranchProducts().length;
            selectedProductInfo.textContent = count + ' products available in selected source branch.';
        }
    }

    function preventSameBranch() {
        if (fromBranch.value && toBranch.value && fromBranch.value === toBranch.value) {
            alert('Source and destination branch cannot be the same.');
            toBranch.value = '';
        }
    }

    fromBranch.addEventListener('change', function () {
        syncSourceBranch();
        preventSameBranch();
    });

    toBranch.addEventListener('change', preventSameBranch);

    productSearch.addEventListener('focus', renderProductResults);
    productSearch.addEventListener('input', renderProductResults);

    productResults.addEventListener('click', function (e) {
        const option = e.target.closest('.product-option[data-id]');
        if (option) selectProduct(option.dataset.id);
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.product-search-box')) {
            productResults.classList.remove('show');
        }
    });

    if (transferTableSearch && transferTable) {
        transferTableSearch.addEventListener('input', function () {
            const term = this.value.trim().toLowerCase();
            transferTable.querySelectorAll('tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    }

    syncSourceBranch();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>