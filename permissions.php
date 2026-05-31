<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();
require_module_read('roles');

$pageTitle = 'Permissions';
require_once __DIR__ . '/includes/header.php';

$db = db();
$isSuper = is_super_admin();
$cid = current_company_id();


/*
|--------------------------------------------------------------------------
| ENSURE ROLE PERMISSION TABLE EXISTS
|--------------------------------------------------------------------------
*/
$db->query("CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id)
)");

/*
|--------------------------------------------------------------------------
| ENSURE PERMISSIONS EXIST (NO AUTO-GRANT)
|--------------------------------------------------------------------------
| This creates missing module/action permissions only. It does NOT assign
| permissions to any role. A role can therefore receive one module only,
| or zero modules, depending on what you tick and save.
*/
if (!function_exists('perm_col_exists')) {
    function perm_col_exists(mysqli $db, string $table, string $column): bool {
        $table = $db->real_escape_string($table);
        $column = $db->real_escape_string($column);
        $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $q && $q->num_rows > 0;
    }
}

$db->query("CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    code VARCHAR(150) NOT NULL UNIQUE
)");

$hasPermModule = perm_col_exists($db, 'permissions', 'module');
$hasPermAction = perm_col_exists($db, 'permissions', 'action');
$hasPermModuleName = perm_col_exists($db, 'permissions', 'module_name');
$hasPermActionName = perm_col_exists($db, 'permissions', 'action_name');
$hasPermDescription = perm_col_exists($db, 'permissions', 'description');
$hasPermCode   = perm_col_exists($db, 'permissions', 'code');

$seedPermissions = [
    'dashboard' => ['read'],
    'pos' => ['read','write','update','delete'],
    'sales' => ['read','write','update','delete','print'],
    'inventory' => ['read','write','update','delete'],
    'products' => ['read','write','update','delete'],
    'product_list' => ['read','write','update','delete'],
    'purchases' => ['read','write','update','delete'],
    'stock_transfer' => ['read','write','update','delete'],
    'stock_movements' => ['read','write','update','delete'],
    'inventory_reports' => ['read','export'],
    'stock' => ['read','write','update','delete'],
    'virtual_stock' => ['read','write'],
    'finance' => ['read','write','update','delete'],
    'financial_statements' => ['read','export'],
    'overview' => ['read'],
    'income_statement' => ['read','export'],
    'balance_sheet' => ['read','export'],
    'chart_of_accounts' => ['read','write','update','delete'],
    'cash_flow_statement' => ['read','export'],
    'owners_equity' => ['read','export'],
    'banks' => ['read','write','update','delete'],
    'bank_transactions' => ['read','write','update','delete'],
    'bank_statement' => ['read','export'],
    'reports' => ['read','export'],
    'expenses' => ['read','write','update','delete'],
    'customers' => ['read','write','update','delete'],
    'suppliers' => ['read','write','update','delete'],
    'users' => ['read','write','update','delete'],
    'roles' => ['read','write','update','delete'],
    'settings' => ['read','write','update'],
    'backup' => ['read','write','delete'],
];

if ($hasPermCode) {
    foreach ($seedPermissions as $module => $actions) {
        foreach ($actions as $action) {
            $code = $module . '.' . $action;
            $existsStmt = $db->prepare("SELECT id FROM permissions WHERE code=? LIMIT 1");
            $existsStmt->bind_param('s', $code);
            $existsStmt->execute();
            $exists = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();
            if ($exists) continue;

            $description = ucwords(str_replace('_', ' ', $action . ' ' . $module));

            if ($hasPermModuleName && $hasPermActionName && $hasPermDescription) {
                $stmt = $db->prepare("INSERT IGNORE INTO permissions (code, module_name, action_name, description) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $code, $module, $action, $description);
            } elseif ($hasPermModuleName && $hasPermActionName) {
                $stmt = $db->prepare("INSERT IGNORE INTO permissions (code, module_name, action_name) VALUES (?, ?, ?)");
                $stmt->bind_param('sss', $code, $module, $action);
            } elseif ($hasPermModule && $hasPermAction) {
                $stmt = $db->prepare("INSERT IGNORE INTO permissions (module, action, code) VALUES (?, ?, ?)");
                $stmt->bind_param('sss', $module, $action, $code);
            } else {
                $stmt = $db->prepare("INSERT IGNORE INTO permissions (code) VALUES (?)");
                $stmt->bind_param('s', $code);
            }
            $stmt->execute();
            $stmt->close();
        }
    }
}


$roleId = (int)($_GET['role_id'] ?? $_POST['role_id'] ?? 0);

if ($roleId <= 0) {
    flash('error', 'Role not selected.');
    redirect('roles.php');
}

$stmt = $db->prepare("
    SELECT r.*, c.name AS company_name
    FROM roles r
    LEFT JOIN companies c ON c.id = r.company_id
    WHERE r.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $roleId);
$stmt->execute();
$role = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$role) {
    flash('error', 'Role not found.');
    redirect('roles.php');
}

$roleCompanyId = (int)($role['company_id'] ?? 0);
$roleName = strtolower(trim((string)($role['name'] ?? '')));
$roleIsSystem = (int)($role['is_system'] ?? 0);

if (!$isSuper && ($roleIsSystem === 1 || $roleCompanyId !== $cid)) {
    flash('error', 'You cannot manage permissions for this role.');
    redirect('roles.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();
    require_module_update('roles');

    $permissionIds = array_map('intval', $_POST['permission_ids'] ?? []);

    $stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $stmt->close();

    foreach ($permissionIds as $pid) {
        if ($pid <= 0) continue;

        $stmt = $db->prepare("
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param('ii', $roleId, $pid);
        $stmt->execute();
        $stmt->close();
    }

    if ((int)(current_user()['role_id'] ?? 0) === $roleId) {
        unset($_SESSION['permissions']);
        rbac_refresh_user_session();
    }

    flash('success', 'Permissions updated successfully.');
    redirect('permissions.php?role_id=' . $roleId);
}

$permissions = all_permissions_grouped();
$selected = role_permission_ids($roleId);

$groups = [
    'Main Operations' => [
        'icon' => 'bi-speedometer2',
        'color' => '#0ea5e9',
        'modules' => [
            'dashboard',
            'pos',
            'sales'
        ]
    ],
    'Inventory Management' => [
        'icon' => 'bi-box-seam',
        'color' => '#2563eb',
        'modules' => [
            'inventory',
            'products',
            'product_list',
            'purchases',
            'stock_transfer',
            'stock_movements',
            'inventory_reports',
            'stock',
            'virtual_stock'
        ]
    ],
    'Financial Management' => [
        'icon' => 'bi-graph-up-arrow',
        'color' => '#16a34a',
        'modules' => [
            'finance',
            'financial_statements',
            'overview',
            'income_statement',
            'balance_sheet',
            'chart_of_accounts',
            'cash_flow_statement',
            'owners_equity'
        ]
    ],
    'Banking' => [
        'icon' => 'bi-bank',
        'color' => '#7c3aed',
        'modules' => [
            'banks',
            'bank_transactions',
            'bank_statement'
        ]
    ],
    'Reports' => [
        'icon' => 'bi-file-earmark-bar-graph',
        'color' => '#f59e0b',
        'modules' => [
            'reports',
            'inventory_reports',
            'sales',
            'expenses'
        ]
    ],
    'Contacts' => [
        'icon' => 'bi-people',
        'color' => '#0891b2',
        'modules' => [
            'customers',
            'suppliers'
        ]
    ],
    'Administration' => [
        'icon' => 'bi-shield-lock',
        'color' => '#0f172a',
        'modules' => [
            'users',
            'roles',
            'settings',
            'backup'
        ]
    ],
];

function nice_module_name(string $module): string {
    return ucwords(str_replace('_', ' ', $module));
}

function nice_permission_name(string $code): string {
    $parts = explode('.', $code);
    $action = end($parts);
    return ucwords(str_replace('_', ' ', $action));
}
?>

<style>
.permissions-wrap{
    display:grid;
    gap:22px;
}

.permissions-hero{
    border-radius:28px;
    padding:28px;
    color:#fff;
    background:linear-gradient(135deg,#0f172a,#1e3a8a,#0f766e);
    box-shadow:0 18px 45px rgba(15,23,42,.16);
}

.permissions-hero h2{
    margin:0;
    font-weight:900;
}

.permissions-hero p{
    margin:6px 0 0;
    color:rgba(255,255,255,.82);
}

.role-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:9px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.13);
    font-size:13px;
    font-weight:800;
}

.top-card{
    border:1px solid #e5e7eb;
    border-radius:24px;
    background:#fff;
    padding:22px;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}

.search-input{
    height:50px;
    border-radius:16px;
    border:1px solid #dbe3ee;
    padding:0 16px;
}

.group-card{
    border:1px solid #e5e7eb;
    border-radius:26px;
    background:#fff;
    overflow:hidden;
    box-shadow:0 14px 34px rgba(15,23,42,.06);
}

.group-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    padding:18px 22px;
    cursor:pointer;
    border-bottom:1px solid #eef2f7;
}

.group-title{
    display:flex;
    align-items:center;
    gap:13px;
    font-weight:900;
    color:#0f172a;
    font-size:18px;
}

.group-icon{
    width:46px;
    height:46px;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:22px;
}

.group-actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.small-btn{
    border:1px solid #dbe3ee;
    background:#f8fafc;
    border-radius:12px;
    padding:8px 12px;
    font-weight:800;
    font-size:12px;
}

.group-body{
    padding:20px;
}

.module-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:16px;
}

.module-box{
    border:1px solid #e2e8f0;
    border-radius:20px;
    padding:16px;
    background:linear-gradient(180deg,#fff,#fbfdff);
}

.module-title{
    font-size:15px;
    font-weight:900;
    color:#0f172a;
    margin-bottom:12px;
}

.permission-item{
    display:flex;
    align-items:center;
    gap:9px;
    padding:9px 10px;
    border-radius:12px;
    transition:.18s;
}

.permission-item:hover{
    background:#f1f5f9;
}

.permission-item label{
    margin:0;
    font-size:13px;
    color:#334155;
    font-weight:600;
    cursor:pointer;
}

.form-check-input{
    cursor:pointer;
}

.save-bar{
    position:sticky;
    bottom:0;
    z-index:20;
    padding:14px;
    background:rgba(255,255,255,.85);
    backdrop-filter:blur(10px);
    border-top:1px solid #e5e7eb;
}

.btn{
    border-radius:14px;
    font-weight:800;
}

.hidden-by-search{
    display:none !important;
}
.module-mini-btn{
    border:1px solid #bfdbfe;
    background:#eff6ff;
    color:#1d4ed8;
    border-radius:10px;
    font-size:11px;
    font-weight:900;
    padding:5px 8px;
}
.module-mini-btn.clear{
    border-color:#fecaca;
    background:#fef2f2;
    color:#b91c1c;
}
.permission-note{
    background:#ecfeff;
    border:1px solid #a5f3fc;
    color:#155e75;
    border-radius:18px;
    padding:12px 14px;
    font-weight:700;
    font-size:13px;
}

@media(max-width:1200px){
    .module-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media(max-width:768px){
    .module-grid{grid-template-columns:1fr;}
    .permissions-hero{padding:22px;}
    .group-head{align-items:flex-start;flex-direction:column;}
}
</style>

<div class="container-fluid py-4 permissions-wrap">

    <div class="permissions-hero">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Permissions</h2>
                <p>Manage grouped module access rights for this role.</p>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <span class="role-badge">
                    <i class="bi bi-person-badge"></i>
                    <?php echo e($role['name']); ?>
                </span>
                <a href="roles.php" class="btn btn-light">
                    <i class="bi bi-arrow-left me-1"></i> Back to Roles
                </a>
            </div>
        </div>
    </div>

    <div class="top-card">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5">
                <label class="form-label fw-bold">Search Permissions</label>
                <input type="text" id="permissionSearch" class="form-control search-input"
                       placeholder="Search module or permission...">
            </div>

            <div class="col-lg-4">
                <div class="fw-bold"><?php echo e($role['name']); ?></div>
                <div class="text-muted small">
                    <?php echo $isSuper ? 'Company: ' . e($role['company_name'] ?: 'System') : 'Company Role'; ?>
                </div>
            </div>

            <div class="col-lg-3 text-lg-end">
                <button type="button" class="btn btn-outline-primary" onclick="expandAllGroups()">
                    <i class="bi bi-arrows-expand me-1"></i> Open All
                </button>
                <button type="button" class="btn btn-outline-dark" onclick="collapseAllGroups()">
                    <i class="bi bi-arrows-collapse me-1"></i> Close All
                </button>
            </div>
        </div>
        <div class="permission-note mt-3">
            You can remove every module, or give only one module to this role. Only checked permissions will be saved.
        </div>
    </div>

    <?php if (can_update('roles')): ?>
    <form method="post" id="permissionsForm">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="role_id" value="<?php echo $roleId; ?>">

        <div class="d-grid gap-3">

            <?php foreach ($groups as $groupName => $group): ?>
                <?php
                    $modulesInGroup = [];
                    foreach ($group['modules'] as $module) {
                        if (isset($permissions[$module])) {
                            $modulesInGroup[$module] = $permissions[$module];
                        }
                    }

                    if (!$modulesInGroup) continue;

                    $groupId = 'group_' . md5($groupName);
                ?>

                <div class="group-card permission-group" data-group="<?php echo e(strtolower($groupName)); ?>">
                    <div class="group-head" onclick="toggleGroup('<?php echo $groupId; ?>')">
                        <div class="group-title">
                            <div class="group-icon" style="background:<?php echo e($group['color']); ?>">
                                <i class="bi <?php echo e($group['icon']); ?>"></i>
                            </div>
                            <div>
                                <?php echo e($groupName); ?>
                                <div class="text-muted small fw-normal">
                                    <?php echo count($modulesInGroup); ?> modules
                                </div>
                            </div>
                        </div>

                        <div class="group-actions" onclick="event.stopPropagation();">
                            <button type="button" class="small-btn" onclick="selectGroup(this, true)">
                                Select All
                            </button>
                            <button type="button" class="small-btn" onclick="selectGroup(this, false)">
                                Clear
                            </button>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                    </div>

                    <div class="group-body" id="<?php echo $groupId; ?>">
                        <div class="module-grid">
                            <?php foreach ($modulesInGroup as $module => $items): ?>
                                <div class="module-box permission-module"
                                     data-search="<?php echo e(strtolower($module . ' ' . nice_module_name($module))); ?>">
                                    <div class="module-title d-flex justify-content-between align-items-center gap-2">
                                        <span><?php echo e(nice_module_name($module)); ?></span>
                                        <span class="d-flex gap-1">
                                            <button type="button" class="module-mini-btn" onclick="selectModule(this, true)">Give</button>
                                            <button type="button" class="module-mini-btn clear" onclick="selectModule(this, false)">Remove</button>
                                        </span>
                                    </div>

                                    <?php foreach ($items as $perm): ?>
                                        <?php
                                            $pid = (int)$perm['id'];
                                            $code = (string)$perm['code'];
                                            $checked = in_array($pid, $selected, true);
                                        ?>
                                        <div class="permission-item"
                                             data-search="<?php echo e(strtolower($module . ' ' . $code)); ?>">
                                            <input
                                                class="form-check-input permission-check"
                                                type="checkbox"
                                                name="permission_ids[]"
                                                value="<?php echo $pid; ?>"
                                                id="perm_<?php echo $roleId; ?>_<?php echo $pid; ?>"
                                                <?php echo $checked ? 'checked' : ''; ?>
                                            >
                                            <label for="perm_<?php echo $roleId; ?>_<?php echo $pid; ?>">
                                                <?php echo e(nice_permission_name($code)); ?>
                                                <span class="text-muted small">
                                                    — <?php echo e($code); ?>
                                                </span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>

                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>

        </div>

        <div class="save-bar mt-4">
            <button class="btn btn-success">
                <i class="bi bi-check2-circle me-1"></i> Save Permissions
            </button>

            <a href="roles.php" class="btn btn-outline-secondary">
                Cancel
            </a>
        </div>
    </form>

    <?php else: ?>
        <div class="alert alert-warning">You do not have permission to edit permissions.</div>
    <?php endif; ?>

</div>

<script>
function toggleGroup(id){
    const body = document.getElementById(id);
    if(!body) return;
    body.style.display = body.style.display === 'none' ? 'block' : 'none';
}

function expandAllGroups(){
    document.querySelectorAll('.group-body').forEach(el => el.style.display = 'block');
}

function collapseAllGroups(){
    document.querySelectorAll('.group-body').forEach(el => el.style.display = 'none');
}

function selectGroup(btn, checked){
    const group = btn.closest('.group-card');
    if(!group) return;

    group.querySelectorAll('.permission-check').forEach(ch => {
        if(ch.closest('.permission-item').style.display !== 'none'){
            ch.checked = checked;
        }
    });
}

function selectModule(btn, checked){
    const moduleBox = btn.closest('.module-box');
    if(!moduleBox) return;
    moduleBox.querySelectorAll('.permission-check').forEach(ch => ch.checked = checked);
}

document.getElementById('permissionSearch')?.addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();

    document.querySelectorAll('.permission-module').forEach(module => {
        let moduleMatch = module.dataset.search.includes(q);
        let anyItem = false;

        module.querySelectorAll('.permission-item').forEach(item => {
            const itemMatch = item.dataset.search.includes(q) || moduleMatch;
            item.style.display = (!q || itemMatch) ? 'flex' : 'none';
            if(!q || itemMatch) anyItem = true;
        });

        module.style.display = anyItem ? 'block' : 'none';
    });

    document.querySelectorAll('.permission-group').forEach(group => {
        const hasVisible = Array.from(group.querySelectorAll('.permission-module'))
            .some(m => m.style.display !== 'none');

        group.style.display = hasVisible ? 'block' : 'none';

        if(q && hasVisible){
            const body = group.querySelector('.group-body');
            if(body) body.style.display = 'block';
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>