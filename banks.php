<?php
$pageTitle = 'Banks';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();
require_module_read('banks');

require_once __DIR__ . '/includes/header.php';

$db = db();
$conn = $db; // compatibility fix
$cid = current_company_id();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['auth_user']) && !isset($_SESSION['user'])) {
    header("Location: /no9_cloud_system_v5/index.php");
    exit;
}

$currentUser = function_exists('current_user') ? current_user() : [];

$companyId = (int)($_SESSION['company_id'] ?? ($currentUser['company_id'] ?? 1));
$userId = (int)($_SESSION['user_id'] ?? ($currentUser['id'] ?? 0));
$isSuperadmin = function_exists('is_super_admin') ? is_super_admin() : (($_SESSION['role'] ?? '') === 'superadmin');
$branchId = $_SESSION['branch_id'] ?? ($currentUser['branch_id'] ?? null);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $accountHolder = trim($_POST['account_holder'] ?? '');
        $currency = trim($_POST['currency'] ?? 'USD');
        $openingBalance = (float)($_POST['opening_balance'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        $postBranchId = $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : $branchId;

        if ($bankName === '') {
            $error = 'Bank name is required.';
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE banks SET bank_name=?, account_number=?, account_holder=?, currency=?, status=?, branch_id=?
                    WHERE id=? AND company_id=?
                ");
                $stmt->bind_param("sssssiii", $bankName, $accountNumber, $accountHolder, $currency, $status, $postBranchId, $id, $companyId);
                $stmt->execute();
                $stmt->close();
                $message = 'Bank updated successfully.';
            } else {
                $currentBalance = $openingBalance;
                $stmt = $conn->prepare("
                    INSERT INTO banks
                    (company_id, branch_id, bank_name, account_number, account_holder, currency, opening_balance, current_balance, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iissssddsi", $companyId, $postBranchId, $bankName, $accountNumber, $accountHolder, $currency, $openingBalance, $currentBalance, $status, $userId);
                $stmt->execute();
                $stmt->close();
                $message = 'Bank added successfully.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM banks WHERE id=? AND company_id=?");
            $stmt->bind_param("ii", $id, $companyId);
            $stmt->execute();
            $stmt->close();
            $message = 'Bank deleted successfully.';
        }
    }
}

$where = "WHERE company_id=?";
$params = [$companyId];
$types = "i";

if (!$isSuperadmin && $branchId !== null) {
    $where .= " AND (branch_id IS NULL OR branch_id=?)";
    $params[] = $branchId;
    $types .= "i";
}

$stmt = $conn->prepare("SELECT * FROM banks $where ORDER BY id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$banks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>No9 Banks</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        body{background:#f5f7fb}
        .card{border:0;border-radius:18px;box-shadow:0 8px 30px rgba(18,38,63,.08)}
        .page-title{font-weight:800}
        .badge-soft{background:#eaf7f0;color:#0f774c}
        .btn-main{background:#0f774c;color:#fff;border-radius:12px}
        .btn-main:hover{background:#0b5e3c;color:#fff}
        .table thead th{background:#0f774c;color:#fff}
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="page-title mb-1"><i class="fa-solid fa-building-columns me-2"></i>Banks</h3>
            <div class="text-muted">Manage company bank accounts and balances.</div>
        </div>
        <a href="bank_transactions.php" class="btn btn-main"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i> Transactions</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?=h($message)?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card p-4">
                <h5 class="mb-3">Add / Edit Bank</h5>
                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="bank_id">
                    <div class="mb-3">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" id="bank_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Number</label>
                        <input type="text" name="account_number" id="account_number" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Holder</label>
                        <input type="text" name="account_holder" id="account_holder" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Currency</label>
                            <select name="currency" id="currency" class="form-select">
                                <option value="USD">USD</option>
                                <option value="SLSH">SLSH</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Opening Balance</label>
                            <input type="number" step="0.01" name="opening_balance" id="opening_balance" class="form-control" value="0">
                            <small class="text-muted">Only used when adding new bank.</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch ID</label>
                        <input type="number" name="branch_id" id="branch_id" class="form-control" value="<?=h($branchId ?? '')?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button class="btn btn-main w-100"><i class="fa-solid fa-save me-1"></i> Save Bank</button>
                    <button type="button" onclick="resetForm()" class="btn btn-light w-100 mt-2">Clear</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card p-4">
                <h5 class="mb-3">Bank Accounts</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Bank</th>
                            <th>Account</th>
                            <th>Currency</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th width="170">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($banks as $b): ?>
                            <tr>
                                <td>
                                    <strong><?=h($b['bank_name'])?></strong><br>
                                    <small class="text-muted">Branch: <?=h($b['branch_id'] ?? 'All')?></small>
                                </td>
                                <td><?=h($b['account_number'])?><br><small><?=h($b['account_holder'])?></small></td>
                                <td><span class="badge badge-soft"><?=h($b['currency'])?></span></td>
                                <td><strong><?=number_format((float)$b['current_balance'], 2)?></strong></td>
                                <td><?=h($b['status'])?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary"
                                        onclick='editBank(<?=json_encode($b, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>)'>
                                        Edit
                                    </button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this bank? Transactions will also be deleted.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?=h($b['id'])?>">
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$banks): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No banks found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<script>
function editBank(b){
    document.getElementById('bank_id').value = b.id || '';
    document.getElementById('bank_name').value = b.bank_name || '';
    document.getElementById('account_number').value = b.account_number || '';
    document.getElementById('account_holder').value = b.account_holder || '';
    document.getElementById('currency').value = b.currency || 'USD';
    document.getElementById('opening_balance').value = b.opening_balance || 0;
    document.getElementById('branch_id').value = b.branch_id || '';
    document.getElementById('status').value = b.status || 'active';
}
function resetForm(){
    document.getElementById('bank_id').value = '';
    document.querySelector('form').reset();
}
</script>
</div>
</body>
</html>
