<?php
/*
|--------------------------------------------------------------------------
| No9 Cloud System - Bank Statement
| Full fixed file
|--------------------------------------------------------------------------
| Fixes:
| - Uses system header/footer so page appears inside the system
| - Missing helper compatibility fixed
| - All Branches support: branch_id = 0 means no branch filter
| - Print only report area, not sidebar/header/system UI
|--------------------------------------------------------------------------
*/

$pageTitle = 'Bank Statement';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
if (file_exists(__DIR__ . '/includes/bank_helpers.php')) {
    require_once __DIR__ . '/includes/bank_helpers.php';
}

if (function_exists('require_login')) {
    require_login();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';

$db = function_exists('db') ? db() : ($conn ?? null);
if (!$db instanceof mysqli) {
    die('<div class="alert alert-danger m-4">Database connection not found.</div>');
}
$conn = $db;

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bank_page_company_id')) {
    function bank_page_company_id(): int {
        if (function_exists('current_company_id')) {
            return (int)current_company_id();
        }
        if (function_exists('no9_current_company_id')) {
            return (int)no9_current_company_id();
        }
        return (int)($_SESSION['company_id'] ?? 0);
    }
}

if (!function_exists('bank_page_selected_branch_id')) {
    function bank_page_selected_branch_id(): int {
        if (isset($_GET['branch_id'])) {
            return max(0, (int)$_GET['branch_id']);
        }

        foreach (['branch_id', 'selected_branch_id', 'current_branch_id'] as $key) {
            if (isset($_SESSION[$key])) {
                return max(0, (int)$_SESSION[$key]);
            }
        }

        if (function_exists('current_branch_id')) {
            return max(0, (int)current_branch_id());
        }

        if (function_exists('no9_current_branch_id')) {
            return max(0, (int)no9_current_branch_id());
        }

        return 0;
    }
}

if (!function_exists('bank_page_is_superadmin')) {
    function bank_page_is_superadmin(): bool {
        if (function_exists('is_super_admin')) {
            return (bool)is_super_admin();
        }
        return (($_SESSION['role'] ?? '') === 'superadmin');
    }
}

if (!function_exists('bank_table_exists')) {
    function bank_table_exists(mysqli $db, string $table): bool {
        $table = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '$table'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('bank_column_exists')) {
    function bank_column_exists(mysqli $db, string $table, string $column): bool {
        $table  = $db->real_escape_string($table);
        $column = $db->real_escape_string($column);
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('bank_fetch_all')) {
    function bank_fetch_all(mysqli $db, string $sql, string $types = '', array $params = []): array {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new Exception($db->error);
        }
        if ($types !== '' && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

$companyId = bank_page_company_id();
$branchId  = bank_page_selected_branch_id();
$isAllBranches = ($branchId <= 0);
$isSuperadmin = bank_page_is_superadmin();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$bankId = (int)($_GET['bank_id'] ?? 0);

$error = '';

$branches = [];
if (bank_table_exists($conn, 'branches')) {
    $branches = bank_fetch_all(
        $conn,
        "SELECT id, name FROM branches WHERE company_id=? ORDER BY name ASC",
        "i",
        [$companyId]
    );
}

$branchName = 'All Branches';
if (!$isAllBranches && $branches) {
    foreach ($branches as $br) {
        if ((int)$br['id'] === (int)$branchId) {
            $branchName = $br['name'];
            break;
        }
    }
}

$bankNameCol = bank_column_exists($conn, 'banks', 'bank_name') ? 'bank_name' : 'name';

$bankWhere = "WHERE company_id=?";
$bankParams = [$companyId];
$bankTypes = "i";

if (bank_column_exists($conn, 'banks', 'status')) {
    $bankWhere .= " AND status='active'";
}

if (!$isAllBranches && bank_column_exists($conn, 'banks', 'branch_id')) {
    $bankWhere .= " AND (branch_id IS NULL OR branch_id=0 OR branch_id=?)";
    $bankParams[] = $branchId;
    $bankTypes .= "i";
}

$banks = [];
$rows = [];

try {
    $banks = bank_fetch_all($conn, "SELECT * FROM banks $bankWhere ORDER BY `$bankNameCol` ASC", $bankTypes, $bankParams);

    $where = "WHERE bt.company_id=? AND bt.transaction_date BETWEEN ? AND ?";
    $params = [$companyId, $from, $to];
    $types = "iss";

    if ($bankId > 0) {
        $where .= " AND bt.bank_id=?";
        $params[] = $bankId;
        $types .= "i";
    }

    /*
    |--------------------------------------------------------------------------
    | All Branches logic:
    | If branchId = 0, DO NOT add branch condition.
    |--------------------------------------------------------------------------
    */
    if (!$isAllBranches && bank_column_exists($conn, 'bank_transactions', 'branch_id')) {
        $where .= " AND (bt.branch_id IS NULL OR bt.branch_id=0 OR bt.branch_id=?)";
        $params[] = $branchId;
        $types .= "i";
    }

    $bankNameSelect = "b.`$bankNameCol` AS bank_name";

    $sql = "
        SELECT bt.*, $bankNameSelect
        FROM bank_transactions bt
        INNER JOIN banks b ON b.id = bt.bank_id
        $where
        ORDER BY bt.transaction_date ASC, bt.id ASC
    ";

    $rows = bank_fetch_all($conn, $sql, $types, $params);
} catch (Throwable $e) {
    $error = 'SQL Error: ' . $e->getMessage();
}

$increaseTypes = ['deposit','transfer_in','sale','invoice_payment','adjustment'];

$totalIn = 0.0;
$totalOut = 0.0;

foreach ($rows as $r) {
    if (in_array($r['type'], $increaseTypes, true)) {
        $totalIn += (float)$r['amount'];
    } else {
        $totalOut += (float)$r['amount'];
    }
}

$net = $totalIn - $totalOut;
$selectedBankName = 'All Banks';
if ($bankId > 0) {
    foreach ($banks as $b) {
        if ((int)$b['id'] === $bankId) {
            $selectedBankName = $b[$bankNameCol] ?? 'Selected Bank';
            break;
        }
    }
}
?>

<style>
.statement-page{display:grid;gap:20px}
.statement-hero{
    border-radius:26px;
    padding:24px;
    color:#fff;
    background:linear-gradient(135deg,#0f172a,#0f774c);
    box-shadow:0 18px 45px rgba(15,23,42,.14);
}
.statement-hero h3{font-weight:900;margin:0}
.scope-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:7px 12px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    font-weight:800;
    font-size:13px;
}
.statement-card{
    border:0;
    border-radius:22px;
    box-shadow:0 12px 34px rgba(15,23,42,.08);
    background:#fff;
}
.btn-main{
    background:#0f774c;
    color:#fff;
    border-radius:12px;
    font-weight:800;
}
.btn-main:hover{background:#0b5e3c;color:#fff}
.summary-card{
    border:0;
    border-radius:20px;
    padding:18px;
    box-shadow:0 12px 32px rgba(15,23,42,.08);
    background:#fff;
    border-left:5px solid #0f774c;
}
.summary-card small{font-weight:800;color:#64748b}
.summary-card h3{font-weight:900;margin:7px 0 0}
.table thead th{background:#0f774c;color:#fff}
.print-header{display:none}
@media print{
    @page{size:A4;margin:12mm}
    body{background:#fff!important}
    body *{visibility:hidden!important}
    #statementPrintArea,#statementPrintArea *{visibility:visible!important}
    #statementPrintArea{
        position:absolute!important;
        left:0!important;
        top:0!important;
        width:100%!important;
        margin:0!important;
        padding:0!important;
    }
    .no-print,.sidebar,.app-sidebar,.topbar,.app-header,.navbar,.btn,form{display:none!important}
    .print-header{display:block!important;text-align:center;border-bottom:2px solid #111827;padding-bottom:10px;margin-bottom:12px}
    .print-header h2{font-size:22px;margin:0;color:#111827}
    .print-header div{font-size:12px;color:#475569;margin-top:4px}
    .statement-hero{display:none!important}
    .statement-card,.summary-card{box-shadow:none!important;border:1px solid #cbd5e1!important;border-radius:8px!important}
    .summary-card{padding:10px!important}
    .summary-card h3{font-size:16px!important}
    table{font-size:12px!important}
}
</style>

<div class="container-fluid py-4 statement-page" id="statementPrintArea">

    <div class="print-header">
        <h2>Bank Statement</h2>
        <div>
            Scope: <?= h($branchName) ?> |
            Bank: <?= h($selectedBankName) ?> |
            From <?= h($from) ?> To <?= h($to) ?> |
            Printed: <?= date('Y-m-d H:i') ?>
        </div>
    </div>

    <div class="statement-hero no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="scope-pill">
                    <i class="bi bi-diagram-3"></i>
                    <?= h($branchName) ?>
                </span>
                <h3 class="mt-3"><i class="bi bi-bank me-2"></i>Bank Statement</h3>
                <div class="opacity-75">From <?= h($from) ?> To <?= h($to) ?></div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a href="banks.php" class="btn btn-light fw-bold">
                    <i class="bi bi-building"></i> Banks
                </a>
                <a href="bank_transactions.php" class="btn btn-light fw-bold">
                    <i class="bi bi-arrow-left-right"></i> Transactions
                </a>
                <button onclick="window.print()" class="btn btn-light fw-bold">
                    <i class="bi bi-printer"></i> Print / PDF
                </button>
            </div>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="statement-card p-4 no-print">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">From</label>
                <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
            </div>

            <div class="col-md-3">
                <label class="form-label">To</label>
                <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label">Bank</label>
                <select name="bank_id" class="form-select">
                    <option value="0">All Banks</option>
                    <?php foreach ($banks as $b): ?>
                        <option value="<?= h($b['id']) ?>" <?= $bankId === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= h($b[$bankNameCol] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-main w-100">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="summary-card">
                <small>Total In</small>
                <h3 class="text-success"><?= number_format($totalIn, 2) ?></h3>
            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card">
                <small>Total Out</small>
                <h3 class="text-danger"><?= number_format($totalOut, 2) ?></h3>
            </div>
        </div>

        <div class="col-md-4">
            <div class="summary-card">
                <small>Net Movement</small>
                <h3><?= number_format($net, 2) ?></h3>
            </div>
        </div>
    </div>

    <div class="statement-card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold mb-1">Statement Details</h5>
                <small class="text-muted">Scope: <?= h($branchName) ?> | Bank: <?= h($selectedBankName) ?></small>
            </div>
            <small class="text-muted no-print"><?= count($rows) ?> records</small>
        </div>

        <div class="table-responsive">
            <table class="table align-middle table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bank</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th class="text-end">Debit / Out</th>
                        <th class="text-end">Credit / In</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php $isIn = in_array($r['type'], $increaseTypes, true); ?>
                        <tr>
                            <td><?= h($r['transaction_date'] ?? '') ?></td>
                            <td><strong><?= h($r['bank_name'] ?? '') ?></strong></td>
                            <td>
                                <?= h(str_replace('_', ' ', ucwords((string)($r['type'] ?? ''), '_'))) ?>
                                <br>
                                <small class="text-muted"><?= h($r['description'] ?? '') ?></small>
                            </td>
                            <td><?= h($r['reference'] ?? '') ?></td>
                            <td class="text-end text-danger fw-bold">
                                <?= !$isIn ? number_format((float)$r['amount'], 2) . ' ' . h($r['currency'] ?? '') : '-' ?>
                            </td>
                            <td class="text-end text-success fw-bold">
                                <?= $isIn ? number_format((float)$r['amount'], 2) . ' ' . h($r['currency'] ?? '') : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th colspan="4" class="text-end">Totals</th>
                        <th class="text-end text-danger"><?= number_format($totalOut, 2) ?></th>
                        <th class="text-end text-success"><?= number_format($totalIn, 2) ?></th>
                    </tr>
                    <tr>
                        <th colspan="4" class="text-end">Net Movement</th>
                        <th colspan="2" class="text-end"><?= number_format($net, 2) ?></th>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">No statement data found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
