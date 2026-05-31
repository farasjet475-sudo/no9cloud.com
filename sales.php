<?php
$pageTitle = 'Sales';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/finance_helpers.php';
require_once __DIR__ . '/includes/stock_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = enforce_sales_branch(current_branch_id());
$db  = db();

/* ========================
   DELETE SALE (ONLY OWN BRANCH)
======================== */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    $stmt = $db->prepare("
        SELECT *
        FROM sales
        WHERE id = ?
          AND company_id = ?
          AND branch_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('iii', $id, $cid, $bid);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($sale) {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("
                SELECT *
                FROM sale_items
                WHERE sale_id = ?
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($items as $it) {
                if (!empty($it['product_id'])) {

                    $stmt = $db->prepare("
                        SELECT id
                        FROM products
                        WHERE id = ?
                          AND company_id = ?
                          AND branch_id = ?
                        LIMIT 1
                    ");
                    $productId = (int)$it['product_id'];
                    $stmt->bind_param('iii', $productId, $cid, $bid);
                    $stmt->execute();
                    $product = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($product) {
                        stock_add($db, $productId, (float)$it['qty']);

                        if (function_exists('stock_record_movement')) {
                            stock_record_movement($db, [
                                'company_id'       => $cid,
                                'branch_id'        => $bid,
                                'product_id'       => $productId,
                                'transaction_type' => 'SALE_DELETE_RETURN',
                                'reference_no'     => $sale['invoice_no'],
                                'qty_in'           => (float)$it['qty'],
                                'qty_out'          => 0,
                                'unit_cost'        => 0,
                                'notes'            => 'Stock restored after sale deletion',
                                'created_by'       => $_SESSION['user']['id'] ?? null,
                            ]);
                        }
                    }
                }
            }

            $stmt = $db->prepare("DELETE FROM sale_items WHERE sale_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare("DELETE FROM sales WHERE id = ? AND company_id = ? AND branch_id = ?");
            $stmt->bind_param('iii', $id, $cid, $bid);
            $stmt->execute();
            $stmt->close();

            $db->commit();
            flash('success', 'Sale deleted and stock restored.');
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    redirect('sales.php');
}

/* ========================
   FILTERS
======================== */
$search = trim($_GET['search'] ?? '');
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';
$customerFilter = (int)($_GET['customer_id'] ?? 0);

/* ========================
   CUSTOMERS (ONLY OWN BRANCH)
======================== */
$stmt = $db->prepare("
    SELECT id, name
    FROM customers
    WHERE company_id = ?
      AND branch_id = ?
    ORDER BY name
");
$stmt->bind_param('ii', $cid, $bid);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========================
   SALES QUERY (ONLY OWN BRANCH)
======================== */
$where = "WHERE s.company_id = ? AND s.branch_id = ?";
$params = [$cid, $bid];
$types = 'ii';

if ($from) {
    $where .= " AND s.sale_date >= ?";
    $params[] = $from;
    $types .= 's';
}
if ($to) {
    $where .= " AND s.sale_date <= ?";
    $params[] = $to;
    $types .= 's';
}
if ($customerFilter > 0) {
    $where .= " AND s.customer_id = ?";
    $params[] = $customerFilter;
    $types .= 'i';
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (
        s.invoice_no LIKE ?
        OR c.name LIKE ?
        OR (
            SELECT GROUP_CONCAT(CONCAT(si.product_name,' x',si.qty) SEPARATOR ', ')
            FROM sale_items si
            WHERE si.sale_id = s.id
        ) LIKE ?
    )";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$sql = "
    SELECT 
        s.*,
        c.name AS customer_name,
        b.name AS branch_name,
        (
            SELECT GROUP_CONCAT(CONCAT(si.product_name,' x',si.qty) SEPARATOR ', ')
            FROM sale_items si
            WHERE si.sale_id = s.id
        ) AS item_summary
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
    LEFT JOIN branches b ON b.id = s.branch_id
    $where
    ORDER BY s.id DESC
";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========================
   KPI
======================== */
$totalSalesCount = count($rows);
$totalSalesValue = 0;
$todaySalesValue = 0;
$todayDate = date('Y-m-d');
$todayCount = 0;

foreach ($rows as $r) {
    $amount = (float)($r['total_amount'] ?? 0);
    $totalSalesValue += $amount;

    if (($r['sale_date'] ?? '') === $todayDate) {
        $todaySalesValue += $amount;
        $todayCount++;
    }
}
?>

<style>
.sales-shell{
    display:grid;
    gap:18px;
}
.hero-card{
    border:0;
    border-radius:24px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
    box-shadow:0 16px 36px rgba(15,23,42,.18);
    padding:26px;
}
.hero-card h3{
    margin:0;
    font-weight:800;
}
.hero-card p{
    margin:6px 0 0;
    color:rgba(255,255,255,.8);
}
.kpi-card{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{
    opacity:.92;
    font-size:.84rem;
}
.kpi-card h3{
    margin:8px 0 0;
    font-size:1.7rem;
    font-weight:800;
}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8);}
.kpi-2{background:linear-gradient(135deg,#10b981,#059669);}
.kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706);}
.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
.soft-card{
    border:0;
    border-radius:22px;
    background:#fff;
    box-shadow:0 12px 30px rgba(24, 93, 255, 0.06);
}
.section-title{
    font-weight:800;
    margin-bottom:6px;
}
.section-sub{
    color:#64748b;
    font-size:14px;
}
.filter-box .form-control,
.filter-box .form-select{
    min-height:46px;
    border-radius:14px;
}
.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
}
.table thead th{
    white-space:nowrap;
    background:#f8fafc;
    color:#334155;
}
.amount-strong{
    font-weight:800;
    color:#0f172a;
}
.customer-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:5px 10px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}
.branch-note{
    color:#64748b;
    font-size:12px;
}
.action-btn{
    border-radius:12px;
}
</style>

<div class="sales-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Sales History</h3>
                <p>Only sales from your assigned branch are shown here.</p>
            </div>
            <div>
                <a href="pos.php" class="btn btn-light">Back to POS</a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Sales Records</small>
                <h3><?php echo $totalSalesCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Total Sales Value</small>
                <h3><?php echo money($totalSalesValue); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Today's Sales Count</small>
                <h3><?php echo $todayCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Today's Sales Value</small>
                <h3><?php echo money($todaySalesValue); ?></h3>
            </div>
        </div>
    </div>

    <div class="soft-card p-4 filter-box">
        <div class="mb-3">
            <div class="section-title">Filter Sales</div>
            <div class="section-sub">Search by receipt number, customer, or sold items from your branch.</div>
        </div>

        <form class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo e($search); ?>" placeholder="Receipt no / customer / items">
            </div>

            <div class="col-md-3">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="0">All Customers</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $customerFilter === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo e($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
            </div>

            <div class="col-md-1 d-flex align-items-end">
                <button class="btn btn-primary w-100">Go</button>
            </div>
        </form>
    </div>

    <div class="soft-card p-4">
        <div class="mb-3">
            <div class="section-title">Sales Table</div>
            <div class="section-sub">All saved receipt sales from your assigned branch only.</div>
        </div>

        <div class="table-wrap">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th width="180">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo e($r['invoice_no']); ?></div>
                                    <div class="branch-note"><?php echo e($r['branch_name'] ?: 'No branch'); ?></div>
                                </td>
                                <td><?php echo e($r['sale_date']); ?></td>
                                <td>
                                    <span class="customer-badge"><?php echo e($r['customer_name'] ?: 'Walk-in'); ?></span>
                                </td>
                                <td><?php echo e($r['item_summary']); ?></td>
                                <td class="amount-strong"><?php echo money($r['total_amount']); ?></td>
                                <td class="text-end">
                                    <a target="_blank" href="invoice.php?id=<?php echo (int)$r['id']; ?>&type=receipt" class="btn btn-sm btn-outline-success action-btn">
                                        Receipt
                                    </a>
                                    <a onclick="return confirm('Delete sale?')" href="?delete=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-danger action-btn">
                                        Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if(!$rows): ?>
                            <tr>
                                <td colspan="6" class="text-center text-secondary py-5">No sales found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>