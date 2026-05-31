<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_company_user();
verify_csrf();

$branchId = (int)($_POST['branch_id'] ?? 0);

function switch_branch_is_company_admin(): bool
{
    $u = function_exists('current_user') ? current_user() : [];

    $role = strtolower(trim((string)(
        $u['role'] ??
        $u['role_name'] ??
        $u['type'] ??
        ''
    )));

    return (function_exists('is_admin') && is_admin())
        || (int)($u['is_admin'] ?? 0) === 1
        || (int)($u['role_id'] ?? 0) === 1
        || in_array($role, ['admin','company_admin','manager','administrator'], true);
}

/*
|--------------------------------------------------------------------------
| All Branches
|--------------------------------------------------------------------------
| branch_id = 0 waxaa loo ogol yahay admin/company admin/manager oo kaliya.
*/
if ($branchId === 0) {

    if (!switch_branch_is_company_admin()) {
        flash('error', 'You are not allowed to view all branches.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
    }

    if (function_exists('set_current_branch')) {
        set_current_branch(0);
    } else {
        $_SESSION['branch_id'] = 0;
    }

    $_SESSION['branch_id'] = 0;

    flash('success', 'All branches selected successfully.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}

/*
|--------------------------------------------------------------------------
| Invalid Branch
|--------------------------------------------------------------------------
*/
if ($branchId < 0) {
    flash('error', 'Invalid branch selected.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}

/*
|--------------------------------------------------------------------------
| Specific Branch Access
|--------------------------------------------------------------------------
*/
require_branch_access($branchId);

if (function_exists('set_current_branch') && set_current_branch($branchId)) {
    flash('success', 'Branch switched successfully.');
} else {
    $_SESSION['branch_id'] = $branchId;
    flash('success', 'Branch switched successfully.');
}

redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');