<?php
$pageTitle='Credit Invoices';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/finance_helpers.php';
require_once __DIR__ . '/includes/invoice_helpers.php';
require_once __DIR__ . '/includes/report_helpers.php';
require_once __DIR__ . '/includes/stock_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();
$db  = db();

if (!defined('UPLOAD_INVOICE_PROOFS')) {
    define('UPLOAD_INVOICE_PROOFS', __DIR__ . '/uploads/invoice_payments/');
}
if (!is_dir(UPLOAD_INVOICE_PROOFS)) {
    @mkdir(UPLOAD_INVOICE_PROOFS, 0777, true);
}

function table_exists_local(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$table'");
    return $q && $q->num_rows > 0;
}

function column_exists_local(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function upload_invoice_proof(string $field): ?string {
    if (empty($_FILES[$field]['name'])) return null;

    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','pdf'];

    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Only JPG, JPEG, PNG, WEBP, and PDF proof files are allowed.');
    }

    $new = uniqid('proof_', true) . '.' . $ext;
    $target = rtrim(UPLOAD_INVOICE_PROOFS, '/\\') . '/' . $new;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        throw new RuntimeException('Failed to upload payment proof.');
    }

    return $new;
}

$hasInvoices     = table_exists_local($db, 'invoices');
$hasDueDate      = $hasInvoices && column_exists_local($db, 'invoices', 'due_date');
$hasStatus       = $hasInvoices && column_exists_local($db, 'invoices', 'status');
$hasPaidAmount   = $hasInvoices && column_exists_local($db, 'invoices', 'paid_amount');
$hasDueAmount    = $hasInvoices && column_exists_local($db, 'invoices', 'due_amount');
$hasPaymentsTbl  = table_exists_local($db, 'invoice_payments');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_invoice') {
        $id = (int)($_POST['id'] ?? 0);
        $invoice = query_one("SELECT * FROM invoices WHERE id=$id AND company_id=$cid AND branch_id=$bid");

        if ($invoice) {
            $db->begin_transaction();
            try {
                $items = $db->query("SELECT * FROM invoice_items WHERE invoice_id=$id")->fetch_all(MYSQLI_ASSOC);

                foreach ($items as $it) {
                    if (!empty($it['product_id'])) {
                        stock_add($db, (int)$it['product_id'], (float)$it['qty']);
                    }
                }

                if ($hasPaymentsTbl) {
                    $db->query("DELETE FROM invoice_payments WHERE invoice_id=$id");
                }

                $db->query("DELETE FROM invoice_items WHERE invoice_id=$id");
                $db->query("DELETE FROM invoices WHERE id=$id");

                $db->commit();
                flash('success', 'Invoice deleted and stock restored.');
            } catch (Throwable $e) {
                $db->rollback();
                flash('error', 'Delete failed: '.$e->getMessage());
            }
        }

        redirect('invoices.php');
    }

    if ($action === 'save_payment') {
        $invoiceId   = (int)($_POST['invoice_id'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $amount      = (float)($_POST['amount'] ?? 0);
        $method      = trim($_POST['method'] ?? 'cash');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $notes       = trim($_POST['payment_notes'] ?? '');

        if (!$hasPaymentsTbl) {
            flash('error', 'invoice_payments table is missing.');
            redirect('invoices.php');
        }

        $invoice = query_one("SELECT * FROM invoices WHERE id=$invoiceId AND company_id=$cid AND branch_id=$bid");
        if (!$invoice) {
            flash('error', 'Invoice not found.');
            redirect('invoices.php');
        }

        if ($amount <= 0) {
            flash('error', 'Payment amount must be greater than 0.');
            redirect('invoices.php');
        }

        $currentDue = (float)($invoice['due_amount'] ?? $invoice['total_amount'] ?? 0);
        if ($amount > $currentDue) {
            flash('error', 'Payment cannot be greater than due amount.');
            redirect('invoices.php');
        }

        if (empty($_FILES['payment_proof']['name'])) {
            flash('error', 'Payment proof is required.');
            redirect('invoices.php');
        }

        $db->begin_transaction();
        try {
            $proofFile = upload_invoice_proof('payment_proof');

            $stmt = $db->prepare("
                INSERT INTO invoice_payments
                (company_id,branch_id,invoice_id,customer_id,payment_date,amount,method,reference_no,proof_file,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $customerId = (int)($invoice['customer_id'] ?? 0);
            $createdBy  = $_SESSION['user']['id'] ?? null;
            $stmt->bind_param(
                'iiiisdssssi',
                $cid,
                $bid,
                $invoiceId,
                $customerId,
                $paymentDate,
                $amount,
                $method,
                $referenceNo,
                $proofFile,
                $notes,
                $createdBy
            );
            if (!$stmt->execute()) {
                throw new Exception('Payment save failed: '.$stmt->error);
            }
            $stmt->close();

            if ($hasPaidAmount && $hasDueAmount) {
                $newPaid = (float)($invoice['paid_amount'] ?? 0) + $amount;
                $newDue  = max(0, (float)($invoice['total_amount'] ?? 0) - $newPaid);

                $newStatus = 'unpaid';
                if ($newDue <= 0) {
                    $newStatus = 'paid';
                } elseif ($newPaid > 0) {
                    $newStatus = 'partial';
                }

                if ($hasDueDate && $newDue > 0 && !empty($invoice['due_date']) && strtotime($invoice['due_date']) < strtotime(date('Y-m-d'))) {
                    $newStatus = 'overdue';
                }

                if ($hasStatus) {
                    $stmt2 = $db->prepare("UPDATE invoices SET paid_amount=?, due_amount=?, status=? WHERE id=? AND company_id=? AND branch_id=?");
                    $stmt2->bind_param('ddsiii', $newPaid, $newDue, $newStatus, $invoiceId, $cid, $bid);
                } else {
                    $stmt2 = $db->prepare("UPDATE invoices SET paid_amount=?, due_amount=? WHERE id=? AND company_id=? AND branch_id=?");
                    $stmt2->bind_param('ddiii', $newPaid, $newDue, $invoiceId, $cid, $bid);
                }

                if (!$stmt2->execute()) {
                    throw new Exception('Invoice payment update failed: '.$stmt2->error);
                }
                $stmt2->close();
            }

            $db->commit();
            flash('success', 'Payment saved with proof successfully.');
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Payment failed: '.$e->getMessage());
        }

        redirect('invoices.php');
    }
}

$search         = trim($_GET['search'] ?? '');
$statusFilter   = trim($_GET['status'] ?? '');
$customerFilter = (int)($_GET['customer_id'] ?? 0);
$from           = $_GET['from'] ?? '';
$to             = $_GET['to'] ?? '';

$customers = $db->query("SELECT id,name FROM customers WHERE company_id=$cid ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$where = "WHERE i.company_id=$cid AND i.branch_id=$bid";

if ($from) $where .= " AND i.invoice_date>='".$db->real_escape_string($from)."'";
if ($to)   $where .= " AND i.invoice_date<='".$db->real_escape_string($to)."'";

if ($search !== '') {
    $safeSearch = $db->real_escape_string($search);
    $where .= " AND (
        i.invoice_no LIKE '%$safeSearch%'
        OR c.name LIKE '%$safeSearch%'
    )";
}

if ($customerFilter > 0) {
    $where .= " AND i.customer_id=".(int)$customerFilter;
}

if ($statusFilter === 'paid') {
    $where .= $hasDueAmount ? " AND COALESCE(i.due_amount,0) <= 0" : "";
} elseif ($statusFilter === 'partial') {
    $where .= ($hasDueAmount && $hasPaidAmount) ? " AND COALESCE(i.paid_amount,0) > 0 AND COALESCE(i.due_amount,0) > 0" : "";
} elseif ($statusFilter === 'unpaid') {
    $where .= $hasPaidAmount ? " AND COALESCE(i.paid_amount,0) <= 0" : "";
} elseif ($statusFilter === 'overdue' && $hasDueDate && $hasDueAmount) {
    $today = date('Y-m-d');
    $where .= " AND i.due_date < '$today' AND COALESCE(i.due_amount,0) > 0";
}

$selectExtra = $hasDueDate ? ", i.due_date" : ", NULL AS due_date";
$selectExtra .= $hasDueAmount ? ", i.due_amount" : ", (i.total_amount) AS due_amount";
$selectExtra .= $hasPaidAmount ? ", i.paid_amount" : ", 0 AS paid_amount";
$selectExtra .= $hasStatus ? ", i.status" : ", '' AS status";

$rows = $hasInvoices
    ? $db->query("
        SELECT i.*, c.name AS customer_name,
               (SELECT GROUP_CONCAT(CONCAT(product_name,' x',qty) SEPARATOR ', ')
                FROM invoice_items ii
                WHERE ii.invoice_id=i.id) AS item_summary
               $selectExtra
        FROM invoices i
        LEFT JOIN customers c ON c.id=i.customer_id
        $where
        ORDER BY i.id DESC
    ")->fetch_all(MYSQLI_ASSOC)
    : [];

$totalInvoices = count($rows);
$totalAmount = 0;
$totalPaid = 0;
$totalDue = 0;
$paidCount = 0;
$partialCount = 0;
$unpaidCount = 0;
$overdueCount = 0;
$overdueNames = [];

foreach ($rows as $r) {
    $amount = (float)($r['total_amount'] ?? 0);
    $paid   = (float)($r['paid_amount'] ?? 0);
    $due    = (float)($r['due_amount'] ?? $amount);

    $totalAmount += $amount;
    $totalPaid   += $paid;
    $totalDue    += $due;

    $isOverdue = !empty($r['due_date']) && $due > 0 && strtotime($r['due_date']) < strtotime(date('Y-m-d'));

    if ($isOverdue) {
        $overdueCount++;
        if (!empty($r['customer_name'])) {
            $overdueNames[] = $r['customer_name'];
        }
    } elseif ($due <= 0) {
        $paidCount++;
    } elseif ($paid > 0) {
        $partialCount++;
    } else {
        $unpaidCount++;
    }
}

$overdueNames = array_values(array_unique($overdueNames));
?>

<style>
.invoice-shell{
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
    box-shadow:0 12px 30px rgba(15,23,42,.06);
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
.alert-overdue{
    border:0;
    border-left:5px solid #dc2626;
    border-radius:18px;
    background:#fef2f2;
    color:#991b1b;
    padding:18px 20px;
}
.status-pill{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
}
.st-paid{background:#dcfce7;color:#166534;}
.st-partial{background:#dbeafe;color:#1d4ed8;}
.st-unpaid{background:#fef3c7;color:#92400e;}
.st-overdue{background:#fee2e2;color:#b91c1c;}
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
.action-btn{
    border-radius:12px;
}
</style>

<div class="invoice-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Credit Invoices</h3>
                <p>Dynamic invoice table from database with overdue customer alerts and payment tracking.</p>
            </div>
            <div>
                <a href="pos.php" class="btn btn-light">Back to POS</a>
            </div>
        </div>
    </div>

    <?php if ($overdueCount > 0): ?>
        <div class="alert-overdue">
            <div class="fw-bold mb-1">Overdue Alert</div>
            <div>
                Waxaa jira <strong><?php echo $overdueCount; ?></strong> invoice overdue ah.
                <?php if ($overdueNames): ?>
                    Macaamiisha daynta ka dhacday waxaa ka mid ah:
                    <strong><?php echo e(implode(', ', array_slice($overdueNames, 0, 8))); ?></strong><?php echo count($overdueNames) > 8 ? ' ...' : ''; ?>.
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Invoices</small>
                <h3><?php echo $totalInvoices; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Total Paid</small>
                <h3><?php echo money($totalPaid); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Total Due</small>
                <h3><?php echo money($totalDue); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Invoice Value</small>
                <h3><?php echo money($totalAmount); ?></h3>
            </div>
        </div>
    </div>

    <div class="soft-card p-4 filter-box">
        <div class="mb-3">
            <div class="section-title">Filter Invoices</div>
            <div class="section-sub">Search by invoice number or customer name, and filter by status/date.</div>
        </div>

        <form class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo e($search); ?>" placeholder="Invoice no / customer">
            </div>

            <div class="col-md-2">
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
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="paid" <?php echo $statusFilter==='paid'?'selected':''; ?>>Paid</option>
                    <option value="partial" <?php echo $statusFilter==='partial'?'selected':''; ?>>Partial</option>
                    <option value="unpaid" <?php echo $statusFilter==='unpaid'?'selected':''; ?>>Unpaid</option>
                    <option value="overdue" <?php echo $statusFilter==='overdue'?'selected':''; ?>>Overdue</option>
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
            <div class="section-title">Invoice Table</div>
            <div class="section-sub">Database records only. Overdue customers are highlighted automatically.</div>
        </div>

        <div class="table-wrap">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Due</th>
                            <th>Status</th>
                            <th width="340">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $r): ?>
                            <?php
                                $due = (float)($r['due_amount'] ?? $r['total_amount']);
                                $paid = (float)($r['paid_amount'] ?? 0);
                                $isOverdue = !empty($r['due_date']) && $due > 0 && strtotime($r['due_date']) < strtotime(date('Y-m-d'));

                                $statusClass = 'st-unpaid';
                                $statusText = 'Unpaid';

                                if ($isOverdue) {
                                    $statusClass = 'st-overdue';
                                    $statusText = 'Overdue';
                                } elseif ($due <= 0) {
                                    $statusClass = 'st-paid';
                                    $statusText = 'Paid';
                                } elseif ($paid > 0) {
                                    $statusClass = 'st-partial';
                                    $statusText = 'Partial';
                                }
                            ?>
                            <tr class="<?php echo $isOverdue ? 'table-danger' : ''; ?>">
                                <td>
                                    <div class="fw-bold"><?php echo e($r['invoice_no']); ?></div>
                                    <?php if (!empty($r['due_date'])): ?>
                                        <div class="small text-muted">Due: <?php echo e($r['due_date']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($r['invoice_date']); ?></td>
                                <td>
                                    <span class="customer-badge"><?php echo e($r['customer_name'] ?: 'Unknown'); ?></span>
                                    <?php if ($isOverdue): ?>
                                        <div class="small text-danger mt-1">
                                            Alert: <?php echo e($r['customer_name']); ?> has overdue debt.
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($r['item_summary']); ?></td>
                                <td class="amount-strong"><?php echo money($r['total_amount']); ?></td>
                                <td><?php echo money($paid); ?></td>
                                <td class="<?php echo $isOverdue ? 'text-danger fw-bold' : 'fw-bold'; ?>">
                                    <?php echo money($due); ?>
                                </td>
                                <td>
                                    <span class="status-pill <?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a target="_blank" href="invoice.php?id=<?php echo (int)$r['id']; ?>&type=invoice" class="btn btn-sm btn-outline-success action-btn">
                                        View
                                    </a>

                                    <a href="pos.php?load_invoice=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary action-btn">
                                        Edit
                                    </a>

                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete invoice?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="action" value="delete_invoice">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger action-btn">Delete</button>
                                    </form>

                                    <?php if ($hasPaymentsTbl && $due > 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-dark action-btn" data-bs-toggle="modal" data-bs-target="#payModal<?php echo (int)$r['id']; ?>">
                                            Pay
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php if ($hasPaymentsTbl && $due > 0): ?>
                            <div class="modal fade" id="payModal<?php echo (int)$r['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="post" enctype="multipart/form-data">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Record Payment - <?php echo e($r['invoice_no']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="save_payment">
                                                <input type="hidden" name="invoice_id" value="<?php echo (int)$r['id']; ?>">

                                                <div class="mb-2">
                                                    <label class="form-label">Payment Date</label>
                                                    <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label">Amount</label>
                                                    <input type="number" step="0.01" min="0.01" max="<?php echo e((string)$due); ?>" name="amount" class="form-control" required>
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label">Method</label>
                                                    <select name="method" class="form-select">
                                                        <option value="cash">Cash</option>
                                                        <option value="bank">Bank</option>
                                                        <option value="zaad">Zaad</option>
                                                        <option value="edahab">E-Dahab</option>
                                                    </select>
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label">Reference No</label>
                                                    <input type="text" name="reference_no" class="form-control">
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label">Payment Proof <span class="text-danger">*</span></label>
                                                    <input type="file" name="payment_proof" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                                                </div>

                                                <div class="mb-2">
                                                    <label class="form-label">Notes</label>
                                                    <textarea name="payment_notes" class="form-control"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button class="btn btn-primary">Save Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if(!$rows): ?>
                            <tr>
                                <td colspan="9" class="text-center text-secondary py-5">No invoices found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>