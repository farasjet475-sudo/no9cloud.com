<?php
/*
|--------------------------------------------------------------------------
| No9 Cloud System - Bank Transactions
| Full fixed file
|--------------------------------------------------------------------------
| Fixes:
| - Missing variables: $companyId, $branchId, $userId, $isSuperadmin, $message, $error
| - All Branches support: branch_id = 0 means no branch filter
| - Uses system header/footer, no duplicate HTML shell problems
| - Safe helpers for old/new projects
|--------------------------------------------------------------------------
*/

$pageTitle = 'Bank Transactions';

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
        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        | 0 = All Branches.
        | We check several common session keys because different headers use
        | different names for the branch dropdown.
        |--------------------------------------------------------------------------
        */
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

if (!function_exists('bank_page_is_company_admin')) {
    function bank_page_is_company_admin(): bool {
        if (function_exists('is_company_admin')) {
            return (bool)is_company_admin();
        }
        $role = strtolower((string)($_SESSION['role'] ?? ''));
        return in_array($role, ['admin', 'company_admin', 'manager'], true);
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

if (!function_exists('bank_fetch_one')) {
    function bank_fetch_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array {
        $rows = bank_fetch_all($db, $sql, $types, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bank_exec')) {
    function bank_exec(mysqli $db, string $sql, string $types = '', array $params = []): bool {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new Exception($db->error);
        }
        if ($types !== '' && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('bank_local_post_transaction')) {
    function bank_local_post_transaction(mysqli $db, array $data): array {
        if (!bank_table_exists($db, 'bank_transactions')) {
            return ['ok' => false, 'message' => 'bank_transactions table not found.'];
        }

        $companyId = (int)$data['company_id'];
        $branchId  = isset($data['branch_id']) && (int)$data['branch_id'] > 0 ? (int)$data['branch_id'] : null;
        $bankId    = (int)$data['bank_id'];
        $type      = trim((string)$data['type']);
        $amount    = (float)$data['amount'];
        $currency  = (string)$data['currency'];
        $reference = trim((string)($data['reference'] ?? ''));
        $desc      = trim((string)($data['description'] ?? ''));
        $date      = (string)($data['transaction_date'] ?? date('Y-m-d'));
        $createdBy = (int)($data['created_by'] ?? 0);

        if ($companyId <= 0 || $bankId <= 0 || $amount <= 0 || $type === '') {
            return ['ok' => false, 'message' => 'Please fill all required fields.'];
        }

        $cols = ['company_id', 'bank_id', 'type', 'amount', 'currency', 'reference', 'description', 'transaction_date'];
        $vals = [$companyId, $bankId, $type, $amount, $currency, $reference, $desc, $date];
        $types = 'iisdssss';

        if (bank_column_exists($db, 'bank_transactions', 'branch_id')) {
            $cols[] = 'branch_id';
            $vals[] = $branchId;
            $types .= 'i';
        }

        if (bank_column_exists($db, 'bank_transactions', 'created_by')) {
            $cols[] = 'created_by';
            $vals[] = $createdBy;
            $types .= 'i';
        }

        if (bank_column_exists($db, 'bank_transactions', 'created_at')) {
            $cols[] = 'created_at';
            $vals[] = date('Y-m-d H:i:s');
            $types .= 's';
        }

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colSql = '`' . implode('`,`', $cols) . '`';

        $increaseTypes = ['deposit','transfer_in','sale','invoice_payment','adjustment'];
        $direction = in_array($type, $increaseTypes, true) ? 1 : -1;

        $db->begin_transaction();

        try {
            bank_exec($db, "INSERT INTO bank_transactions ($colSql) VALUES ($placeholders)", $types, $vals);

            foreach (['current_balance', 'balance'] as $balanceCol) {
                if (bank_column_exists($db, 'banks', $balanceCol)) {
                    $delta = $direction * $amount;
                    bank_exec(
                        $db,
                        "UPDATE banks SET `$balanceCol` = COALESCE(`$balanceCol`,0) + ? WHERE id=? AND company_id=?",
                        "dii",
                        [$delta, $bankId, $companyId]
                    );
                    break;
                }
            }

            $db->commit();
            return ['ok' => true, 'message' => 'Transaction posted successfully.'];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

$companyId    = bank_page_company_id();
$branchId     = bank_page_selected_branch_id();
$isAllBranches = ($branchId <= 0);
$isSuperadmin = bank_page_is_superadmin();
$isCompanyAdmin = bank_page_is_company_admin();
$userId       = (int)($_SESSION['user_id'] ?? 0);

$message = '';
$error   = '';

$increaseTypes = ['deposit','transfer_in','sale','invoice_payment','adjustment'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $bankId = (int)($_POST['bank_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');
    $reference = trim($_POST['reference'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $postBranchId = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $branchId;

    if ($bankId <= 0) {
        $error = 'Please choose a bank account.';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } else {
        $bankRow = bank_fetch_one(
            $conn,
            "SELECT * FROM banks WHERE id=? AND company_id=? LIMIT 1",
            "ii",
            [$bankId, $companyId]
        );

        if (!$bankRow) {
            $error = 'Bank account not found.';
        } else {
            $currency = $bankRow['currency'] ?? 'USD';

            $payload = [
                'company_id' => $companyId,
                'branch_id' => $postBranchId > 0 ? $postBranchId : null,
                'bank_id' => $bankId,
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                'description' => $description,
                'transaction_date' => $transactionDate,
                'created_by' => $userId
            ];

            if (function_exists('bank_post_transaction')) {
                $result = bank_post_transaction($conn, $payload);
            } else {
                $result = bank_local_post_transaction($conn, $payload);
            }

            if (!empty($result['ok'])) {
                $message = $result['message'] ?? 'Transaction posted successfully.';
            } else {
                $error = $result['message'] ?? 'Transaction failed.';
            }
        }
    }
}

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

/*
|--------------------------------------------------------------------------
| Banks dropdown
|--------------------------------------------------------------------------
*/
$bankWhere = "WHERE company_id=?";
$bankParams = [$companyId];
$bankTypes = "i";

if (bank_column_exists($conn, 'banks', 'status')) {
    $bankWhere .= " AND status='active'";
}

/*
Only filter by branch when a specific branch is selected.
All Branches = no branch filter.
*/
if (!$isAllBranches && bank_column_exists($conn, 'banks', 'branch_id')) {
    $bankWhere .= " AND (branch_id IS NULL OR branch_id=0 OR branch_id=?)";
    $bankParams[] = $branchId;
    $bankTypes .= "i";
}

$bankNameCol = bank_column_exists($conn, 'banks', 'bank_name') ? 'bank_name' : 'name';
$banks = bank_fetch_all($conn, "SELECT * FROM banks $bankWhere ORDER BY `$bankNameCol` ASC", $bankTypes, $bankParams);

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$filterBankId = (int)($_GET['bank_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Transactions
|--------------------------------------------------------------------------
*/
$where = "WHERE bt.company_id=? AND bt.transaction_date BETWEEN ? AND ?";
$params = [$companyId, $from, $to];
$types = "iss";

if ($filterBankId > 0) {
    $where .= " AND bt.bank_id=?";
    $params[] = $filterBankId;
    $types .= "i";
}

if (!$isAllBranches && bank_column_exists($conn, 'bank_transactions', 'branch_id')) {
    $where .= " AND (bt.branch_id IS NULL OR bt.branch_id=0 OR bt.branch_id=?)";
    $params[] = $branchId;
    $types .= "i";
}

$accountCol = bank_column_exists($conn, 'banks', 'account_number') ? 'b.account_number' : "'' AS account_number";
$bankNameSelect = "b.`$bankNameCol` AS bank_name";

$sql = "
    SELECT bt.*, $bankNameSelect, $accountCol
    FROM bank_transactions bt
    INNER JOIN banks b ON b.id = bt.bank_id
    $where
    ORDER BY bt.transaction_date DESC, bt.id DESC
";

$transactions = [];
try {
    $transactions = bank_fetch_all($conn, $sql, $types, $params);
} catch (Throwable $e) {
    $error = 'SQL Error: ' . $e->getMessage();
}

$totalIn = 0.0;
$totalOut = 0.0;

foreach ($transactions as $t) {
    $isIn = in_array($t['type'], $increaseTypes, true);
    if ($isIn) {
        $totalIn += (float)$t['amount'];
    } else {
        $totalOut += (float)$t['amount'];
    }
}
$net = $totalIn - $totalOut;
?>

<style>
.bank-page{display:grid;gap:20px}
.bank-hero{
    border-radius:26px;
    padding:24px;
    color:#fff;
    background:linear-gradient(135deg,#0f172a,#0f774c);
    box-shadow:0 18px 45px rgba(15,23,42,.14);
}
.bank-hero h3{font-weight:900;margin:0}
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
.bank-card{
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
.money-in{color:#0f774c;font-weight:900}
.money-out{color:#dc3545;font-weight:900}
@media print{
    .no-print,.sidebar,.app-sidebar,.topbar,.app-header,.navbar,.btn,form{display:none!important}
    body{background:#fff!important}
    .container-fluid,.content,.main,.app-main{width:100%!important;max-width:100%!important;margin:0!important;padding:0!important}
    .bank-card,.summary-card{box-shadow:none!important;border:1px solid #ddd!important}
}
</style>

<div class="container-fluid py-4 bank-page">

    <div class="bank-hero no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="scope-pill">
                    <i class="bi bi-diagram-3"></i>
                    <?= h($branchName) ?>
                </span>
                <h3 class="mt-3"><i class="bi bi-arrow-left-right me-2"></i>Bank Transactions</h3>
                <div class="opacity-75">Deposits, withdrawals, sales, expenses and invoice payments.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="banks.php" class="btn btn-light fw-bold">Banks</a>
                <a href="bank_statement.php" class="btn btn-light fw-bold">Statement</a>
                <button onclick="window.print()" class="btn btn-light fw-bold"><i class="bi bi-printer"></i> Print</button>
            </div>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success no-print"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="summary-card">
                <small>Total In</small>
                <h3 class="money-in"><?= number_format($totalIn, 2) ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <small>Total Out</small>
                <h3 class="money-out"><?= number_format($totalOut, 2) ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <small>Net Movement</small>
                <h3><?= number_format($net, 2) ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4 no-print">
            <div class="bank-card p-4">
                <h5 class="fw-bold mb-3">Post Transaction</h5>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Bank</label>
                        <select name="bank_id" class="form-select" required>
                            <option value="">Choose bank</option>
                            <?php foreach ($banks as $b): 
                                $balCol = bank_column_exists($conn, 'banks', 'current_balance') ? 'current_balance' : (bank_column_exists($conn, 'banks', 'balance') ? 'balance' : '');
                                $balance = $balCol ? (float)($b[$balCol] ?? 0) : 0;
                                $displayBankName = $b[$bankNameCol] ?? '';
                            ?>
                                <option value="<?= h($b['id']) ?>">
                                    <?= h($displayBankName) ?> - <?= h($b['currency'] ?? '') ?> - Bal: <?= number_format($balance, 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($isAllBranches && $branches): ?>
                        <div class="mb-3">
                            <label class="form-label">Post To Branch</label>
                            <select name="branch_id" class="form-select">
                                <option value="0">No specific branch</option>
                                <?php foreach ($branches as $br): ?>
                                    <option value="<?= h($br['id']) ?>"><?= h($br['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="branch_id" value="<?= h($branchId) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            <option value="deposit">Deposit / Cash In</option>
                            <option value="withdraw">Withdraw / Cash Out</option>
                            <option value="sale">POS Sale</option>
                            <option value="expense">Expense</option>
                            <option value="invoice_payment">Invoice Payment</option>
                            <option value="adjustment">Adjustment</option>
                            <option value="transfer_in">Transfer In</option>
                            <option value="transfer_out">Transfer Out</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="Receipt / invoice / transfer reference">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>

                    <button class="btn btn-main w-100">
                        <i class="bi bi-check-circle me-1"></i> Post Transaction
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="bank-card p-4">
                <form method="get" class="row g-2 mb-3 no-print">
                    <div class="col-md-3">
                        <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <select name="bank_id" class="form-select">
                            <option value="0">All banks</option>
                            <?php foreach ($banks as $b): ?>
                                <option value="<?= h($b['id']) ?>" <?= $filterBankId === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= h($b[$bankNameCol] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-main w-100">Filter</button>
                    </div>
                </form>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">Transaction List</h5>
                    <small class="text-muted">From <?= h($from) ?> To <?= h($to) ?></small>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Bank</th>
                                <th>Type</th>
                                <th>Ref / Description</th>
                                <th class="text-end">In</th>
                                <th class="text-end">Out</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $t):
                            $isIn = in_array($t['type'], $increaseTypes, true);
                        ?>
                            <tr>
                                <td><?= h($t['transaction_date'] ?? '') ?></td>
                                <td>
                                    <strong><?= h($t['bank_name'] ?? '') ?></strong><br>
                                    <small class="text-muted"><?= h($t['account_number'] ?? '') ?></small>
                                </td>
                                <td><?= h(str_replace('_', ' ', ucwords((string)($t['type'] ?? ''), '_'))) ?></td>
                                <td>
                                    <?= h($t['reference'] ?? '') ?><br>
                                    <small class="text-muted"><?= h($t['description'] ?? '') ?></small>
                                </td>
                                <td class="text-end money-in">
                                    <?= $isIn ? number_format((float)$t['amount'], 2) . ' ' . h($t['currency'] ?? '') : '-' ?>
                                </td>
                                <td class="text-end money-out">
                                    <?= !$isIn ? number_format((float)$t['amount'], 2) . ' ' . h($t['currency'] ?? '') : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$transactions): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No transactions found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
