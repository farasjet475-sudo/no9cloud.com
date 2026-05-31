<?php
$pageTitle = 'Subscription Portal';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();

require_once __DIR__ . '/includes/header.php';

if (function_exists('is_super_admin') && is_super_admin()) {
    redirect('dashboard.php');
}

$db  = db();
$cid = (int)current_company_id();

if (!function_exists('sp_e')) {
    function sp_e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sp_money')) {
    function sp_money($amount): string {
        if (function_exists('money')) return money($amount);
        return '$' . number_format((float)$amount, 2);
    }
}

if (!function_exists('sp_table_exists')) {
    function sp_table_exists(mysqli $db, string $table): bool {
        $table = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '$table'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('sp_column_exists')) {
    function sp_column_exists(mysqli $db, string $table, string $column): bool {
        $table  = $db->real_escape_string($table);
        $column = $db->real_escape_string($column);
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('sp_ensure_tables')) {
    function sp_ensure_tables(mysqli $db): void {
        $db->query("CREATE TABLE IF NOT EXISTS plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            code VARCHAR(60) NULL,
            description TEXT NULL,
            price_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_branches INT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            plan_id INT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            start_date DATE NULL,
            end_date DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX(company_id), INDEX(plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS payment_proofs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            subscription_id INT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(80) NULL,
            reference_no VARCHAR(150) NULL,
            proof_file VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            admin_note TEXT NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(company_id), INDEX(subscription_id), INDEX(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

sp_ensure_tables($db);
$error = '';

$stmt = $db->prepare("SELECT s.*, p.name AS plan_name, p.code AS plan_code, p.price_monthly, p.description AS plan_description
    FROM subscriptions s LEFT JOIN plans p ON p.id = s.plan_id
    WHERE s.company_id = ? ORDER BY s.id DESC LIMIT 1");
$stmt->bind_param('i', $cid);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $amount    = (float)($_POST['amount'] ?? 0);
    $method    = trim($_POST['payment_method'] ?? '');
    $reference = trim($_POST['reference_no'] ?? '');
    $subId     = (int)($current['id'] ?? 0);

    if ($amount <= 0) $error = 'Amount is required.';
    elseif ($method === '') $error = 'Payment method is required.';
    elseif ($subId <= 0) $error = 'No subscription found for this company.';
    elseif (empty($_FILES['proof_file']['name'])) $error = 'Proof file is required.';

    $proofFile = '';
    if ($error === '') {
        if (function_exists('upload_file')) {
            $uploadPath = defined('UPLOAD_PAYMENTS') ? UPLOAD_PAYMENTS : (__DIR__ . '/uploads/payments');
            $proofFile = upload_file('proof_file', $uploadPath, ['jpg','jpeg','png','pdf','webp']);
        } else {
            $uploadDir = __DIR__ . '/uploads/payments';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
            $ext = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','pdf','webp'];
            if (!in_array($ext, $allowed, true)) {
                $error = 'Only JPG, PNG, WEBP, and PDF files are allowed.';
            } else {
                $safeName = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target = $uploadDir . '/' . $safeName;
                if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $target)) $proofFile = 'uploads/payments/' . $safeName;
                else $error = 'Unable to upload proof file.';
            }
        }
    }

    if ($error === '') {
        $stmt = $db->prepare("INSERT INTO payment_proofs(company_id, subscription_id, amount, payment_method, reference_no, proof_file, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param('iidsss', $cid, $subId, $amount, $method, $reference, $proofFile);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        if (function_exists('log_activity')) log_activity('create', 'payment_proofs', $newId);
        flash('success', 'Payment proof uploaded. Admin will review it manually.');
        redirect('subscription_portal.php');
    }
}

$plans = sp_column_exists($db, 'plans', 'status')
    ? $db->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order ASC, id ASC")->fetch_all(MYSQLI_ASSOC)
    : $db->query("SELECT * FROM plans ORDER BY sort_order ASC, id ASC")->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("SELECT pp.*, s.start_date, s.end_date, p.name AS plan_name
    FROM payment_proofs pp
    LEFT JOIN subscriptions s ON s.id = pp.subscription_id
    LEFT JOIN plans p ON p.id = s.plan_id
    WHERE pp.company_id = ? ORDER BY pp.id DESC");
$stmt->bind_param('i', $cid);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$today = date('Y-m-d');
$isExpired = !empty($current['end_date']) && $current['end_date'] < $today;
?>
<style>
.sub-portal{display:grid;gap:22px}.sub-hero{border-radius:28px;padding:28px;color:#fff;background:linear-gradient(135deg,#0f172a,#1d4ed8 55%,#0f766e);box-shadow:0 18px 40px rgba(15,23,42,.14)}.sub-hero h2{font-weight:900;margin:0 0 6px}.sub-hero p{margin:0;color:rgba(255,255,255,.84)}.soft-card{border:0;border-radius:24px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.07)}.soft-card .card-body{padding:24px}.plan-card{border:1px solid #e2e8f0;border-radius:20px;padding:18px;height:100%;background:#fff}.plan-card h5{font-weight:900;margin-bottom:6px}.plan-price{font-size:26px;font-weight:900;color:#1d4ed8}.status-pill{display:inline-flex;padding:7px 12px;border-radius:999px;font-weight:800;font-size:12px}.status-active{background:#dcfce7;color:#166534}.status-expired{background:#fee2e2;color:#991b1b}.status-pending{background:#fef3c7;color:#92400e}.status-suspended{background:#fee2e2;color:#991b1b}.status-trial{background:#ede9fe;color:#6d28d9}.form-control,.form-select{border-radius:14px;min-height:46px}.btn{border-radius:14px;font-weight:800}.table thead th{background:#f8fafc;color:#334155}
</style>
<div class="container-fluid py-4 sub-portal">
    <div class="sub-hero"><h2>Subscription Portal</h2><p>View your plan, upload payment proof, and track approval status.</p></div>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo sp_e($error); ?></div><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-7"><div class="soft-card h-100"><div class="card-body">
            <h5 class="fw-bold mb-3">Current Subscription</h5>
            <?php if ($current): ?>
                <?php $status=strtolower((string)$current['status']); if($isExpired && $status==='active') $status='expired'; $class='status-active'; if($status==='expired')$class='status-expired'; elseif($status==='pending')$class='status-pending'; elseif($status==='suspended')$class='status-suspended'; elseif($status==='trial')$class='status-trial'; ?>
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><div class="plan-card"><div class="text-muted small">Plan</div><h5><?php echo sp_e($current['plan_name'] ?? 'Unknown Plan'); ?></h5><div class="plan-price"><?php echo sp_money($current['price_monthly'] ?? 0); ?><span class="fs-6 text-muted"> / month</span></div></div></div>
                    <div class="col-md-6"><div class="plan-card"><div class="text-muted small">Period</div><h5><?php echo sp_e($current['start_date'] ?? '-'); ?> to <?php echo sp_e($current['end_date'] ?? '-'); ?></h5><span class="status-pill <?php echo $class; ?>"><?php echo sp_e(ucfirst($status)); ?></span></div></div>
                </div>
            <?php else: ?><div class="alert alert-warning mb-4">No subscription found for this company. Contact SaaS admin.</div><?php endif; ?>
            <h6 class="fw-bold">Available Plans</h6><div class="row g-3">
                <?php foreach ($plans as $p): ?><div class="col-md-4"><div class="plan-card"><h5><?php echo sp_e($p['name'] ?? 'Plan'); ?></h5><div class="plan-price"><?php echo sp_money($p['price_monthly'] ?? 0); ?></div><div class="text-muted small"><?php echo sp_e($p['description'] ?? ''); ?></div><div class="text-muted small mt-2"><?php echo ((int)($p['max_branches'] ?? 0) > 0) ? (int)$p['max_branches'].' branches' : 'Unlimited branches'; ?></div></div></div><?php endforeach; ?>
                <?php if (!$plans): ?><div class="col-12 text-muted">No plans configured yet.</div><?php endif; ?>
            </div>
        </div></div></div>
        <div class="col-lg-5"><div class="soft-card h-100"><div class="card-body">
            <h5 class="fw-bold mb-3">Upload Payment Proof</h5>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="mb-3"><label class="form-label fw-bold">Amount in USD</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">Payment Method</label><select name="payment_method" class="form-select" required><option value="EVC Plus">EVC Plus</option><option value="Zaad">Zaad</option><option value="eDahab">eDahab</option><option value="Sahal">Sahal</option><option value="Bank Transfer">Bank Transfer</option><option value="Cash">Cash</option></select></div>
                <div class="mb-3"><label class="form-label fw-bold">Reference / Transaction No</label><input name="reference_no" class="form-control"></div>
                <div class="mb-3"><label class="form-label fw-bold">Proof File</label><input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required></div>
                <button class="btn btn-primary w-100" <?php echo !$current ? 'disabled' : ''; ?>>Submit for Approval</button>
            </form>
        </div></div></div>
    </div>
    <div class="soft-card"><div class="card-body"><h5 class="fw-bold mb-3">Payment History</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date</th><th>Plan</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th><th>Proof</th></tr></thead><tbody>
        <?php foreach ($payments as $p): ?><?php $st=strtolower((string)($p['status'] ?? 'pending')); $stClass=$st==='approved'?'status-active':($st==='rejected'?'status-expired':'status-pending'); ?><tr><td><?php echo sp_e($p['created_at'] ?? ''); ?></td><td><?php echo sp_e($p['plan_name'] ?? '-'); ?></td><td><?php echo sp_e($p['payment_method'] ?? ''); ?></td><td><?php echo sp_money($p['amount'] ?? 0); ?></td><td><?php echo sp_e($p['reference_no'] ?? ''); ?></td><td><span class="status-pill <?php echo $stClass; ?>"><?php echo sp_e(ucfirst($st)); ?></span></td><td><?php if (!empty($p['proof_file'])): ?><a href="<?php echo sp_e($p['proof_file']); ?>" target="_blank">View</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted py-4">No payment history found.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
