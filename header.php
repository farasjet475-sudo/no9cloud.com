<?php
require_once __DIR__ . '/auth.php';

require_login();
write_guard();

$flashSuccess = flash('success');
$flashError   = flash('error');
$pageTitle    = $pageTitle ?? 'Dashboard';

$subscription = is_super_admin()
    ? ['state' => 'active', 'days_left' => 9999, 'label' => 'Active']
    : current_subscription_status();

$notifCount = unread_notifications_count();

if (is_super_admin()) {
    $branding = [
        'title'   => APP_NAME,
        'name'    => APP_NAME,
        'logo'    => '',
        'tagline' => 'Cloud inventory, finance, and multi-branch SaaS',
    ];
} else {
    $branding = function_exists('company_branding') ? company_branding() : [
        'title'          => APP_NAME,
        'name'           => APP_NAME,
        'logo'           => '',
        'tagline'        => 'Inventory, finance, and branch management',
        'email'          => '',
        'phone'          => '',
        'address'        => '',
        'invoice_footer' => 'Thank you for your business.',
    ];
}

$lowStock = (!is_super_admin() && function_exists('low_stock_items')) ? low_stock_items() : [];

if (!function_exists('header_user_role')) {
    function header_user_role(): string {
        $u = function_exists('current_user') ? current_user() : [];
        return strtolower(trim((string)($u['role'] ?? $u['role_name'] ?? $u['type'] ?? '')));
    }
}

if (!function_exists('header_is_company_admin')) {
    function header_is_company_admin(): bool {
        $u    = function_exists('current_user') ? current_user() : [];
        $role = header_user_role();
        return (function_exists('is_admin') && is_admin())
            || (int)($u['is_admin']  ?? 0) === 1
            || (int)($u['role_id']   ?? 0) === 1
            || in_array($role, ['admin','company_admin','manager','administrator'], true);
    }
}

if (!function_exists('header_can_access_pos')) {
    function header_can_access_pos(): bool {
        /*
        |--------------------------------------------------------------------------
        | POS ACCESS IN HEADER
        |--------------------------------------------------------------------------
        | Requirement: every logged-in system user must see POS in the top header.
        | Superadmin, company admin, cashier, seller, and normal users all get the POS
        | button. Page-level security can still be handled inside pos.php if needed.
        |--------------------------------------------------------------------------
        */
        return true;
    }
}

/*
|--------------------------------------------------------------------------
| BRANCHES FOR TOPBAR DROPDOWN
|--------------------------------------------------------------------------
| Admins see all branches + "All Branches" (value 0).
| Regular users see only their own branch.
|--------------------------------------------------------------------------
*/
$branches = [];
if (!is_super_admin()) {
    $allBranches = function_exists('company_branches') ? company_branches() : [];
    if (header_is_company_admin()) {
        $branches = $allBranches;
    } else {
        $currentBranchId = function_exists('current_branch_id') ? (int)current_branch_id() : 0;
        foreach ($allBranches as $b) {
            if ((int)$b['id'] === $currentBranchId) { $branches[] = $b; break; }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ACTIVE BRANCH — read from session first so switch_branch.php takes effect
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) session_start();

$activeBranchId = null;
foreach (['branch_id','selected_branch_id','current_branch_id','active_branch_id'] as $key) {
    if (array_key_exists($key, $_SESSION)) { $activeBranchId = (int)$_SESSION[$key]; break; }
}
if ($activeBranchId === null) {
    $activeBranchId = function_exists('current_branch_id') ? (int)current_branch_id() : 0;
}

$canShowPosButton = header_can_access_pos();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?php echo e($pageTitle); ?> - <?php echo e($branding['title'] ?? APP_NAME); ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.topbar-pos-btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:7px 14px;border-radius:12px;
    border:1px solid rgba(37,99,235,.15);
    background:linear-gradient(135deg,#1d4ed8,#0f766e);
    color:#fff !important;text-decoration:none;
    font-weight:700;box-shadow:0 8px 18px rgba(15,23,42,.10);transition:.2s ease;
}
.topbar-pos-btn:hover{transform:translateY(-1px);box-shadow:0 12px 22px rgba(15,23,42,.14);color:#fff !important;}
.topbar-icon-btn{
    min-width:38px;min-height:38px;
    display:inline-flex;align-items:center;justify-content:center;border-radius:10px;
}
.brand-logo-top{
    width:34px;height:34px;object-fit:cover;
    border-radius:10px;border:1px solid rgba(148,163,184,.25);background:#fff;
}
.branch-select{
    min-width:180px;border-radius:12px;font-weight:700;
    border:1px solid #e2e8f0;background:#fff;
    padding:6px 30px 6px 10px;font-size:13px;cursor:pointer;
    appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' fill='%231d4ed8' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 9px center;
    box-shadow:0 1px 4px rgba(15,23,42,.06);
    transition:border-color .15s,box-shadow .15s;
}
.branch-select:focus{
    outline:none;border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.15);
}
.branch-select option[value="0"]{font-weight:800;color:#1d4ed8;}
</style>
</head>

<body>
<div class="app-shell">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="main">

        <div class="topbar d-flex justify-content-between align-items-center flex-wrap gap-2">

            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($branding['logo'])): ?>
                    <img src="<?php echo e($branding['logo']); ?>" alt="Logo" class="brand-logo-top">
                <?php endif; ?>
                <div>
                    <h5 class="mb-0"><?php echo e($pageTitle); ?></h5>
                    <div class="small-muted">
                        <?php echo is_super_admin() ? 'Super Admin / SaaS Control' : e($branding['name'] ?? APP_NAME); ?>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">

                <?php if ($canShowPosButton): ?>
                    <a href="pos.php" class="topbar-pos-btn" title="Open POS">
                        <i class="bi bi-cart-check"></i>
                        <span>POS</span>
                    </a>
                <?php endif; ?>

                <?php if (!is_super_admin() && !empty($branches)): ?>
                    <form action="switch_branch.php" method="post"
                          class="d-flex align-items-center gap-1 mb-0" id="branchSwitchForm">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                        <select name="branch_id"
                                class="branch-select"
                                title="Switch branch"
                                onchange="this.form.submit()">

                            <?php if (header_is_company_admin()): ?>
                                <option value="0" <?php echo $activeBranchId === 0 ? 'selected' : ''; ?>>
                                    &#x2295; All Branches
                                </option>
                            <?php endif; ?>

                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>"
                                    <?php echo $activeBranchId === (int)$b['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </form>
                <?php endif; ?>

                <a href="profile.php"
                   class="btn btn-outline-secondary btn-sm topbar-icon-btn" title="Profile">
                    <i class="bi bi-person-circle"></i>
                </a>

                <a href="notifications.php"
                   class="btn btn-outline-primary btn-sm topbar-icon-btn position-relative"
                   title="Notifications">
                    <i class="bi bi-bell"></i>
                    <?php if ($notifCount): ?>
                        <span class="badge text-bg-danger position-absolute top-0 start-100 translate-middle">
                            <?php echo (int)$notifCount; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <a href="healthcheck.php"
                   class="btn btn-outline-secondary btn-sm topbar-icon-btn" title="System Health">
                    <i class="bi bi-heart-pulse"></i>
                </a>

                <a href="logout.php"
                   class="btn btn-outline-dark btn-sm topbar-icon-btn" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>

            </div>
        </div><!-- /topbar -->

        <div class="content">

            <?php if (!is_super_admin() && in_array($subscription['state'], ['expired','suspended'], true)): ?>
                <div class="readonly-banner">
                    Your account is in
                    <strong><?php echo e($subscription['label'] ?? $subscription['state']); ?></strong> state.
                    Data can be viewed, but changes are blocked until payment is approved.
                </div>
            <?php elseif (!is_super_admin() && isset($subscription['days_left']) && $subscription['days_left'] <= 5): ?>
                <div class="alert alert-warning">
                    Subscription expires in <?php echo (int)$subscription['days_left']; ?> day(s).
                    Please upload payment proof to avoid read-only mode.
                </div>
            <?php endif; ?>

            <?php if (!is_super_admin() && !empty($lowStock)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Low stock alert: <?php echo count($lowStock); ?> product(s) need restocking.
                </div>
            <?php endif; ?>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success"><?php echo e($flashSuccess); ?></div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert alert-danger"><?php echo e($flashError); ?></div>
            <?php endif; ?>