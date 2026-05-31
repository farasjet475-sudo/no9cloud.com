<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();
require_super_admin();

$pageTitle = 'Subscriptions';
require_once __DIR__ . '/includes/header.php';

$db = db();

if (!function_exists('sub_e')) { function sub_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('sub_money')) { function sub_money($amount): string { return function_exists('money') ? money($amount) : '$' . number_format((float)$amount, 2); } }
if (!function_exists('sub_table_exists')) { function sub_table_exists(mysqli $db, string $table): bool { $table=$db->real_escape_string($table); $res=$db->query("SHOW TABLES LIKE '$table'"); return $res && $res->num_rows>0; } }
if (!function_exists('sub_column_exists')) { function sub_column_exists(mysqli $db, string $table, string $column): bool { $table=$db->real_escape_string($table); $column=$db->real_escape_string($column); $res=$db->query("SHOW COLUMNS FROM `$table` LIKE '$column'"); return $res && $res->num_rows>0; } }
if (!function_exists('sub_fetch_one')) { function sub_fetch_one(mysqli $db, string $sql, string $types='', array $params=[]): ?array { $stmt=$db->prepare($sql); if(!$stmt)return null; if($types!==''&&$params)$stmt->bind_param($types,...$params); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $row?:null; } }
if (!function_exists('sub_ensure_tables')) {
    function sub_ensure_tables(mysqli $db): void {
        $db->query("CREATE TABLE IF NOT EXISTS plans (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, code VARCHAR(60) NULL, description TEXT NULL, price_monthly DECIMAL(12,2) NOT NULL DEFAULT 0, max_branches INT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, plan_id INT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'active', start_date DATE NULL, end_date DATE NULL, notes TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, INDEX(company_id), INDEX(plan_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS payment_proofs (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, subscription_id INT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, payment_method VARCHAR(80) NULL, reference_no VARCHAR(150) NULL, proof_file VARCHAR(255) NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', admin_note TEXT NULL, reviewed_by INT NULL, reviewed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(company_id), INDEX(subscription_id), INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
sub_ensure_tables($db);

$error = '';
$prefillCompanyId = (int)($_GET['company_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (function_exists('write_guard')) write_guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_subscription') {
        $id=(int)($_POST['id']??0); $companyId=(int)($_POST['company_id']??0); $planId=(int)($_POST['plan_id']??0);
        $status=trim($_POST['status']??'active'); $startDate=trim($_POST['start_date']??''); $endDate=trim($_POST['end_date']??''); $notes=trim($_POST['notes']??'');
        if(!in_array($status,['active','expired','suspended','trial','pending'],true)) $status='active';
        if($companyId<=0||$planId<=0||$startDate===''||$endDate==='') $error='Company, plan, start date, and end date are required.';
        else {
            if($id>0){
                $stmt=$db->prepare("UPDATE subscriptions SET company_id=?, plan_id=?, status=?, start_date=?, end_date=?, notes=? WHERE id=?");
                $stmt->bind_param('iissssi',$companyId,$planId,$status,$startDate,$endDate,$notes,$id); $stmt->execute(); $stmt->close();
                if(function_exists('log_activity')) log_activity('update','subscriptions',$id);
                flash('success','Subscription updated successfully.');
            } else {
                $stmt=$db->prepare("INSERT INTO subscriptions(company_id,plan_id,status,start_date,end_date,notes) VALUES(?,?,?,?,?,?)");
                $stmt->bind_param('iissss',$companyId,$planId,$status,$startDate,$endDate,$notes); $stmt->execute(); $newId=(int)$stmt->insert_id; $stmt->close();
                if(function_exists('log_activity')) log_activity('create','subscriptions',$newId);
                flash('success','Subscription added successfully.');
            }
            redirect('subscriptions.php?company_id='.$companyId);
        }
    }

    if ($action === 'delete_subscription') {
        $id=(int)($_POST['id']??0);
        if($id>0){$stmt=$db->prepare("DELETE FROM subscriptions WHERE id=?"); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); if(function_exists('log_activity')) log_activity('delete','subscriptions',$id); flash('success','Subscription deleted successfully.');}
        redirect('subscriptions.php');
    }

    if ($action === 'review_payment') {
        $paymentId=(int)($_POST['payment_id']??0); $status=trim($_POST['payment_status']??'pending'); $adminNote=trim($_POST['admin_note']??''); $userId=(int)($_SESSION['user_id']??0);
        if(!in_array($status,['pending','approved','rejected'],true)) $status='pending';
        if($paymentId>0){
            $stmt=$db->prepare("UPDATE payment_proofs SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
            $stmt->bind_param('ssii',$status,$adminNote,$userId,$paymentId); $stmt->execute(); $stmt->close();
            if(function_exists('log_activity')) log_activity('update','payment_proofs',$paymentId);
            flash('success','Payment proof reviewed successfully.');
        }
        redirect('subscriptions.php#payment-proofs');
    }
}

$edit=null;
if(isset($_GET['edit'])){ $editId=(int)$_GET['edit']; $edit=sub_fetch_one($db,"SELECT * FROM subscriptions WHERE id=? LIMIT 1",'i',[$editId]); if($edit)$prefillCompanyId=(int)($edit['company_id']??$prefillCompanyId); }

$search=trim($_GET['search']??''); $statusFilter=trim($_GET['status']??''); $planFilter=(int)($_GET['plan_id']??0); $companyFilter=(int)($_GET['company_id']??$prefillCompanyId);
$companies = sub_table_exists($db,'companies') ? $db->query("SELECT id,name FROM companies ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC) : [];
$plans = $db->query("SELECT id,name,code,price_monthly,max_branches FROM plans ORDER BY sort_order ASC,id ASC")->fetch_all(MYSQLI_ASSOC);
$currentCompany = ($companyFilter>0 && sub_table_exists($db,'companies')) ? sub_fetch_one($db,"SELECT * FROM companies WHERE id=? LIMIT 1",'i',[$companyFilter]) : null;

$where="WHERE 1=1"; $types=''; $params=[];
if($search!==''){ $like='%'.$search.'%'; $where.=" AND (c.name LIKE ? OR p.name LIKE ? OR p.code LIKE ? OR s.notes LIKE ?)"; $types.='ssss'; array_push($params,$like,$like,$like,$like); }
if($statusFilter!==''){ $where.=" AND s.status=?"; $types.='s'; $params[]=$statusFilter; }
if($planFilter>0){ $where.=" AND s.plan_id=?"; $types.='i'; $params[]=$planFilter; }
if($companyFilter>0){ $where.=" AND s.company_id=?"; $types.='i'; $params[]=$companyFilter; }
$stmt=$db->prepare("SELECT s.*, c.name AS company_name, p.name AS plan_name, p.code AS plan_code, p.price_monthly, p.max_branches FROM subscriptions s LEFT JOIN companies c ON c.id=s.company_id LEFT JOIN plans p ON p.id=s.plan_id $where ORDER BY s.id DESC");
if($types!=='')$stmt->bind_param($types,...$params); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$paymentWhere="WHERE 1=1"; $paymentTypes=''; $paymentParams=[];
if($companyFilter>0){ $paymentWhere.=" AND pp.company_id=?"; $paymentTypes.='i'; $paymentParams[]=$companyFilter; }
$stmt=$db->prepare("SELECT pp.*, c.name AS company_name, p.name AS plan_name FROM payment_proofs pp LEFT JOIN companies c ON c.id=pp.company_id LEFT JOIN subscriptions s ON s.id=pp.subscription_id LEFT JOIN plans p ON p.id=s.plan_id $paymentWhere ORDER BY pp.id DESC LIMIT 100");
if($paymentTypes!=='')$stmt->bind_param($paymentTypes,...$paymentParams); $stmt->execute(); $paymentProofs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$totalSubscriptions=count($rows); $activeCount=0; $expiredCount=0; $suspendedCount=0; $totalMonthlyValue=0;
foreach($rows as $r){$status=strtolower((string)($r['status']??'')); if($status==='active')$activeCount++; if($status==='expired')$expiredCount++; if($status==='suspended')$suspendedCount++; $totalMonthlyValue+=(float)($r['price_monthly']??0);}
?>
<style>
.sub-page{display:grid;gap:20px}.hero-card{border:0;border-radius:28px;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:28px;box-shadow:0 18px 40px rgba(15,23,42,.14)}.hero-card h2{margin:0;font-weight:900;font-size:28px}.hero-card p{margin:8px 0 0;color:rgba(255,255,255,.80)}.kpi-card{border-radius:22px;padding:18px 20px;color:#fff;box-shadow:0 10px 24px rgba(0,0,0,.10);height:100%}.kpi-card small{display:block;opacity:.9}.kpi-card h3{margin:8px 0 0;font-size:1.8rem;font-weight:900}.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}.kpi-2{background:linear-gradient(135deg,#10b981,#059669)}.kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706)}.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}.panel-card{border:0;border-radius:24px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.06)}.panel-body{padding:24px}.panel-title{font-size:20px;font-weight:900;color:#0f172a;margin-bottom:4px}.panel-sub{color:#64748b;font-size:14px;margin-bottom:18px}.form-label{font-weight:800;color:#334155}.form-control,.form-select{min-height:48px;border-radius:14px;border:1px solid #dbe2ea}.btn{border-radius:14px;font-weight:800}.table-wrap{border:1px solid #e2e8f0;border-radius:20px;overflow:hidden}.table thead th{background:#f8fafc;color:#334155;white-space:nowrap}.plan-badge,.status-badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800}.plan-badge{background:#eff6ff;color:#1d4ed8}.status-active{background:#dcfce7;color:#166534}.status-expired{background:#fee2e2;color:#b91c1c}.status-suspended{background:#fef3c7;color:#92400e}.status-trial{background:#ede9fe;color:#6d28d9}.status-pending{background:#fef3c7;color:#92400e}.status-approved{background:#dcfce7;color:#166534}.status-rejected{background:#fee2e2;color:#991b1b}.muted-line{color:#64748b;font-size:12px}.company-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.10);color:#fff;font-size:13px;font-weight:800}
</style>
<div class="container-fluid py-4 sub-page">
    <div class="hero-card"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3"><div><h2>Subscriptions</h2><p>Manage company plans, payment proofs, and subscription status.</p></div><div class="d-flex gap-2 flex-wrap"><?php if(!empty($currentCompany['id'])): ?><span class="company-pill"><?php echo sub_e($currentCompany['name']); ?></span><a href="companies.php?edit=<?php echo (int)$currentCompany['id']; ?>" class="btn btn-light">Open Company</a><?php endif; ?><a href="dashboard.php" class="btn btn-light">Back</a></div></div></div>
    <?php if($error): ?><div class="alert alert-danger"><?php echo sub_e($error); ?></div><?php endif; ?>
    <div class="row g-3"><div class="col-md-3"><div class="kpi-card kpi-1"><small>Total Subscriptions</small><h3><?php echo $totalSubscriptions; ?></h3></div></div><div class="col-md-3"><div class="kpi-card kpi-2"><small>Active</small><h3><?php echo $activeCount; ?></h3></div></div><div class="col-md-3"><div class="kpi-card kpi-3"><small>Expired</small><h3><?php echo $expiredCount; ?></h3></div></div><div class="col-md-3"><div class="kpi-card kpi-4"><small>Total Plan Value / Month</small><h3><?php echo sub_money($totalMonthlyValue); ?></h3></div></div></div>
    <div class="row g-4"><div class="col-lg-4"><div class="panel-card"><div class="panel-body"><div class="panel-title"><?php echo $edit?'Edit Subscription':'Add Subscription'; ?></div><div class="panel-sub">Choose company, plan, dates, and status.</div><form method="post"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="save_subscription"><input type="hidden" name="id" value="<?php echo (int)($edit['id']??0); ?>"><div class="mb-3"><label class="form-label">Company</label><select name="company_id" class="form-select" required><option value="">Select company</option><?php foreach($companies as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)($edit['company_id']??$companyFilter)===(int)$c['id'])?'selected':''; ?>><?php echo sub_e($c['name']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Plan</label><select name="plan_id" class="form-select" required><option value="">Select plan</option><?php foreach($plans as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)($edit['plan_id']??0)===(int)$p['id'])?'selected':''; ?>><?php echo sub_e($p['name']); ?> - <?php echo sub_money($p['price_monthly']); ?></option><?php endforeach; ?></select></div><div class="row g-2"><div class="col-md-6"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" required value="<?php echo sub_e($edit['start_date']??date('Y-m-d')); ?>"></div><div class="col-md-6"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" required value="<?php echo sub_e($edit['end_date']??date('Y-m-d',strtotime('+30 days'))); ?>"></div></div><div class="mt-3 mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach(['active','expired','suspended','trial','pending'] as $st): ?><option value="<?php echo $st; ?>" <?php echo (($edit['status']??'active')===$st)?'selected':''; ?>><?php echo ucfirst($st); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="4"><?php echo sub_e($edit['notes']??''); ?></textarea></div><button class="btn btn-primary w-100"><?php echo $edit?'Update Subscription':'Save Subscription'; ?></button><?php if($edit): ?><a href="subscriptions.php<?php echo $companyFilter>0?'?company_id='.(int)$companyFilter:''; ?>" class="btn btn-outline-secondary w-100 mt-2">Cancel</a><?php endif; ?></form></div></div></div>
        <div class="col-lg-8"><div class="panel-card mb-4"><div class="panel-body"><div class="panel-title">Search Subscriptions</div><div class="panel-sub">Filter by company, plan, status, or notes.</div><form class="row g-3"><div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo sub_e($search); ?>"></div><div class="col-md-3"><select name="company_id" class="form-select"><option value="0">All Companies</option><?php foreach($companies as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $companyFilter===(int)$c['id']?'selected':''; ?>><?php echo sub_e($c['name']); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select name="plan_id" class="form-select"><option value="0">All Plans</option><?php foreach($plans as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $planFilter===(int)$p['id']?'selected':''; ?>><?php echo sub_e($p['name']); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><select name="status" class="form-select"><option value="">All Status</option><?php foreach(['active','expired','suspended','trial','pending'] as $st): ?><option value="<?php echo $st; ?>" <?php echo $statusFilter===$st?'selected':''; ?>><?php echo ucfirst($st); ?></option><?php endforeach; ?></select></div><div class="col-md-1"><button class="btn btn-dark w-100">Go</button></div></form></div></div><div class="panel-card"><div class="panel-body"><div class="panel-title">Subscription List</div><div class="panel-sub">Overview of company subscriptions and plans.</div><div class="table-wrap"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Company</th><th>Plan</th><th>Start</th><th>End</th><th>Status</th><th>Notes</th><th width="220"></th></tr></thead><tbody><?php foreach($rows as $r): ?><?php $status=strtolower((string)$r['status']); $class='status-active'; if($status==='expired')$class='status-expired'; elseif($status==='suspended')$class='status-suspended'; elseif($status==='trial')$class='status-trial'; elseif($status==='pending')$class='status-pending'; ?><tr><td><div class="fw-bold"><?php echo sub_e($r['company_name']); ?></div><div class="muted-line"><a href="companies.php?edit=<?php echo (int)$r['company_id']; ?>">Open Company</a></div></td><td><span class="plan-badge"><?php echo sub_e($r['plan_name']); ?></span><div class="muted-line"><?php echo sub_e($r['plan_code']); ?> | <?php echo sub_money($r['price_monthly']); ?> | <?php echo ((int)($r['max_branches']??0)>0)?(int)$r['max_branches'].' branches':'Unlimited branches'; ?></div></td><td><?php echo sub_e($r['start_date']); ?></td><td><?php echo sub_e($r['end_date']); ?></td><td><span class="status-badge <?php echo $class; ?>"><?php echo sub_e(ucfirst($status)); ?></span></td><td><?php echo sub_e($r['notes']); ?></td><td class="text-end"><a href="?edit=<?php echo (int)$r['id']; ?><?php echo $companyFilter>0?'&company_id='.(int)$companyFilter:''; ?>" class="btn btn-sm btn-outline-primary">Edit</a><form method="post" class="d-inline" onsubmit="return confirm('Delete this subscription?')"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="delete_subscription"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5">No subscriptions found.</td></tr><?php endif; ?></tbody></table></div></div></div></div></div></div>
    <div class="panel-card" id="payment-proofs"><div class="panel-body"><div class="panel-title">Payment Proofs</div><div class="panel-sub">Review payment proof uploads from company admins.</div><div class="table-wrap"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date</th><th>Company</th><th>Plan</th><th>Method</th><th>Amount</th><th>Reference</th><th>Proof</th><th>Status</th><th width="260">Review</th></tr></thead><tbody><?php foreach($paymentProofs as $p): ?><?php $pst=strtolower((string)($p['status']??'pending')); $pclass=$pst==='approved'?'status-approved':($pst==='rejected'?'status-rejected':'status-pending'); ?><tr><td><?php echo sub_e($p['created_at']); ?></td><td><?php echo sub_e($p['company_name']); ?></td><td><?php echo sub_e($p['plan_name']??'-'); ?></td><td><?php echo sub_e($p['payment_method']); ?></td><td><?php echo sub_money($p['amount']); ?></td><td><?php echo sub_e($p['reference_no']); ?></td><td><?php if(!empty($p['proof_file'])): ?><a href="<?php echo sub_e($p['proof_file']); ?>" target="_blank">View</a><?php else: ?>-<?php endif; ?></td><td><span class="status-badge <?php echo $pclass; ?>"><?php echo sub_e(ucfirst($pst)); ?></span></td><td><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="action" value="review_payment"><input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>"><select name="payment_status" class="form-select form-select-sm"><option value="pending" <?php echo $pst==='pending'?'selected':''; ?>>Pending</option><option value="approved" <?php echo $pst==='approved'?'selected':''; ?>>Approved</option><option value="rejected" <?php echo $pst==='rejected'?'selected':''; ?>>Rejected</option></select><button class="btn btn-sm btn-primary">Save</button></form></td></tr><?php endforeach; ?><?php if(!$paymentProofs): ?><tr><td colspan="9" class="text-center text-muted py-5">No payment proofs found.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
