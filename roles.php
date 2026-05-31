<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();
require_module_read('roles');

$pageTitle = 'Roles Management';
require_once __DIR__ . '/includes/header.php';

$db = db();
$isSuper = is_super_admin();
$cid = current_company_id();

/*
|--------------------------------------------------------------------------
| ENSURE ROLE TABLE STRUCTURE
|--------------------------------------------------------------------------
*/
$db->query("CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NULL,
    name VARCHAR(100) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");


function roles_table_has_column(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

$hasIsSystem = roles_table_has_column($db, 'roles', 'is_system');
$hasCompanyId = roles_table_has_column($db, 'roles', 'company_id');

if (!$hasCompanyId) {
    $db->query("ALTER TABLE roles ADD COLUMN company_id INT NULL AFTER id");
    $hasCompanyId = true;
}
if (!$hasIsSystem) {
    $db->query("ALTER TABLE roles ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name");
    $hasIsSystem = true;
}
if (!roles_table_has_column($db, 'roles', 'created_at')) {
    $db->query("ALTER TABLE roles ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();

    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_role') {
        require_module_write('roles');

        $name = trim($_POST['name'] ?? '');
        $targetCompanyId = $isSuper ? (int)($_POST['company_id'] ?? 0) : (int)$cid;
        $isSystem = ($isSuper && !empty($_POST['is_system'])) ? 1 : 0;

        if ($name === '') {
            flash('error', 'Role name is required.');
            redirect('roles.php');
        }

        if (strtolower($name) === 'superadmin' && !$isSuper) {
            flash('error', 'Only superadmin can manage superadmin role.');
            redirect('roles.php');
        }

        if (!$isSystem && $targetCompanyId <= 0) {
            flash('error', 'Please select a company for this role.');
            redirect('roles.php');
        }

        if ($isSystem) {
            $dup = $db->prepare("SELECT id FROM roles WHERE LOWER(name)=LOWER(?) AND company_id IS NULL LIMIT 1");
            $dup->bind_param('s', $name);
        } else {
            $dup = $db->prepare("SELECT id FROM roles WHERE LOWER(name)=LOWER(?) AND company_id=? LIMIT 1");
            $dup->bind_param('si', $name, $targetCompanyId);
        }
        $dup->execute();
        $exists = $dup->get_result()->fetch_assoc();
        $dup->close();

        if ($exists) {
            flash('error', 'This role already exists for the selected scope.');
            redirect('roles.php');
        }

        if ($isSystem) {
            $stmt = $db->prepare("INSERT INTO roles (company_id, name, is_system) VALUES (NULL, ?, 1)");
            $stmt->bind_param('s', $name);
        } else {
            $stmt = $db->prepare("INSERT INTO roles (company_id, name, is_system) VALUES (?, ?, 0)");
            $stmt->bind_param('is', $targetCompanyId, $name);
        }

        $stmt->execute();
        $stmt->close();

        flash('success', 'Role created successfully.');
        redirect('roles.php');
    }

    if ($action === 'delete_role') {
        require_module_delete('roles');

        $roleId = (int)($_POST['role_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM roles WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $role = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$role) {
            flash('error', 'Role not found.');
            redirect('roles.php');
        }

        $roleCompanyId = isset($role['company_id']) ? (int)$role['company_id'] : 0;
        $roleName = strtolower(trim((string)($role['name'] ?? '')));
        $roleIsSystem = (int)($role['is_system'] ?? 0);

        if ($roleName === 'superadmin') {
            flash('error', 'Superadmin role cannot be deleted.');
            redirect('roles.php');
        }

        if (!$isSuper && ($roleIsSystem === 1 || $roleCompanyId !== (int)$cid)) {
            flash('error', 'You cannot delete this role.');
            redirect('roles.php');
        }

        $stmt = $db->prepare("SELECT COUNT(*) total FROM users WHERE role_id=?");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $used = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)($used['total'] ?? 0) > 0) {
            flash('error', 'This role is assigned to users. Remove it from users first.');
            redirect('roles.php');
        }

        $stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id=?");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("DELETE FROM roles WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $stmt->close();

        flash('success', 'Role deleted successfully.');
        redirect('roles.php');
    }
}

$search = trim($_GET['search'] ?? '');
$scope = trim($_GET['scope'] ?? 'all');
$companyFilter = $isSuper ? (int)($_GET['company_id'] ?? 0) : (int)$cid;

$companies = [];
if ($isSuper) {
    $companies = $db->query("SELECT id, name FROM companies ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
}

$params = [];
$types = '';

if ($isSuper) {
    $sql = "
        SELECT r.*, c.name AS company_name,
               (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users_count
        FROM roles r
        LEFT JOIN companies c ON c.id = r.company_id
        WHERE 1=1
    ";

    if ($scope === 'system') {
        $sql .= " AND r.company_id IS NULL";
    } elseif ($scope === 'company') {
        $sql .= " AND r.company_id IS NOT NULL";
    }

    if ($companyFilter > 0) {
        $sql .= " AND r.company_id=?";
        $params[] = $companyFilter;
        $types .= 'i';
    }

    if ($search !== '') {
        $sql .= " AND (r.name LIKE ? OR c.name LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    $sql .= " ORDER BY COALESCE(c.name, 'System') ASC, r.is_system DESC, r.name ASC";
} else {
    $sql = "
        SELECT r.*, NULL AS company_name,
               (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.company_id = ?) AS users_count
        FROM roles r
        WHERE r.company_id = ?
    ";
    $params[] = $cid;
    $params[] = $cid;
    $types .= 'ii';

    if ($search !== '') {
        $sql .= " AND r.name LIKE ?";
        $like = '%' . $search . '%';
        $params[] = $like;
        $types .= 's';
    }

    $sql .= " ORDER BY r.name ASC";
}

$stmt = $db->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$roles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalRoles = count($roles);
$systemRoles = 0;
$companyRoles = 0;
$totalAssignedUsers = 0;
foreach ($roles as $r) {
    if ((int)($r['is_system'] ?? 0) === 1 || empty($r['company_id'])) $systemRoles++;
    else $companyRoles++;
    $totalAssignedUsers += (int)($r['users_count'] ?? 0);
}
?>

<style>
.roles-modern{display:grid;gap:22px}.roles-hero{border:0;border-radius:28px;background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 58%,#0f766e 100%);color:#fff;padding:28px;box-shadow:0 20px 44px rgba(15,23,42,.16);overflow:hidden;position:relative}.roles-hero:after{content:"";position:absolute;right:-80px;top:-80px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.10)}.roles-hero h2{margin:0;font-weight:900;letter-spacing:-.5px}.roles-hero p{margin:8px 0 0;color:rgba(255,255,255,.82)}.hero-icon{width:58px;height:58px;border-radius:20px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:26px}.stat-box{border:0;border-radius:24px;padding:20px;background:#fff;box-shadow:0 14px 34px rgba(15,23,42,.07);height:100%;position:relative;overflow:hidden}.stat-box:before{content:"";position:absolute;left:0;top:0;width:5px;height:100%;background:linear-gradient(#2563eb,#0f766e)}.stat-label{color:#64748b;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.35px}.stat-value{font-size:30px;font-weight:900;color:#0f172a;margin-top:5px}.modern-card{border:1px solid #e5e7eb;border-radius:26px;background:#fff;box-shadow:0 14px 34px rgba(15,23,42,.06)}.modern-card-body{padding:24px}.card-title{font-weight:900;color:#0f172a;font-size:20px;margin:0}.card-sub{color:#64748b;font-size:14px;margin-top:4px}.form-control,.form-select{min-height:50px;border-radius:16px;border:1px solid #dbe3ee;box-shadow:none}.form-control:focus,.form-select:focus{border-color:#93c5fd;box-shadow:0 0 0 4px rgba(37,99,235,.09)}.form-label{font-size:12px;font-weight:900;color:#334155;text-transform:uppercase;letter-spacing:.35px}.btn{border-radius:15px;font-weight:800}.scope-pill{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:900}.pill-system{background:#ede9fe;color:#6d28d9}.pill-company{background:#dbeafe;color:#1d4ed8}.pill-company-name{background:#ecfeff;color:#0f766e}.role-row{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;padding:18px;border:1px solid #e5e7eb;border-radius:20px;background:linear-gradient(180deg,#fff,#fbfdff);transition:.2s ease}.role-row:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(15,23,42,.07);border-color:#cbd5e1}.role-title{font-size:19px;font-weight:900;color:#0f172a}.role-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.role-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.empty-state{padding:48px;text-align:center;color:#64748b}.helper-box{border-radius:18px;background:#f8fafc;border:1px dashed #cbd5e1;padding:14px;color:#475569;font-size:14px}@media(max-width:768px){.role-row{grid-template-columns:1fr}.role-actions{justify-content:flex-start}.roles-hero{padding:22px}}
</style>

<div class="container-fluid py-4 roles-modern">
    <div class="roles-hero">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative">
            <div class="d-flex align-items-center gap-3">
                <div class="hero-icon"><i class="bi bi-shield-lock"></i></div>
                <div>
                    <h2>Roles Management</h2>
                    <p><?php echo $isSuper ? 'Superadmin controls system roles and company-specific roles separately.' : 'Your company roles are private and separated from other companies.'; ?></p>
                </div>
            </div>
            <a href="dashboard.php" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i> Back</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4"><div class="stat-box"><div class="stat-label">Total Roles</div><div class="stat-value"><?php echo (int)$totalRoles; ?></div></div></div>
        <div class="col-md-4"><div class="stat-box"><div class="stat-label">Company Roles</div><div class="stat-value"><?php echo (int)$companyRoles; ?></div></div></div>
        <div class="col-md-4"><div class="stat-box"><div class="stat-label"><?php echo $isSuper ? 'System Roles' : 'Assigned Users'; ?></div><div class="stat-value"><?php echo $isSuper ? (int)$systemRoles : (int)$totalAssignedUsers; ?></div></div></div>
    </div>

    <?php if (can_write('roles')): ?>
    <div class="modern-card">
        <div class="modern-card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div><h5 class="card-title">Create New Role</h5><div class="card-sub">Duplicate names are blocked inside the same company/scope.</div></div>
            </div>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="create_role">
                <div class="col-lg-4">
                    <label class="form-label">Role Name</label>
                    <input type="text" name="name" class="form-control" placeholder="Example: Store Keeper" required>
                </div>

                <?php if ($isSuper): ?>
                <div class="col-lg-4">
                    <label class="form-label">Company Scope</label>
                    <select name="company_id" class="form-select">
                        <option value="0">Select company for company role</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?php echo (int)$company['id']; ?>"><?php echo e($company['name']); ?> (ID: <?php echo (int)$company['id']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_system" value="1" id="is_system">
                        <label class="form-check-label fw-bold" for="is_system">System Role</label>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-lg-<?php echo $isSuper ? '2' : '4'; ?> d-flex align-items-end">
                    <button class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i> Create</button>
                </div>
            </form>
            <div class="helper-box mt-3">
                <?php echo $isSuper ? 'System role = global role. Company role = belongs only to selected company. Company admins see only their own roles.' : 'You can only create and manage roles for your own company.'; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modern-card">
        <div class="modern-card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div><h5 class="card-title">Filter Roles</h5><div class="card-sub">Search without mixing companies.</div></div>
            </div>
            <form method="get" class="row g-3">
                <div class="col-lg-<?php echo $isSuper ? '5' : '10'; ?>">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search role name<?php echo $isSuper ? ' or company' : ''; ?>..." value="<?php echo e($search); ?>">
                </div>
                <?php if ($isSuper): ?>
                <div class="col-lg-3">
                    <label class="form-label">Scope</label>
                    <select name="scope" class="form-select">
                        <option value="all" <?php echo $scope==='all'?'selected':''; ?>>All scopes</option>
                        <option value="system" <?php echo $scope==='system'?'selected':''; ?>>System roles only</option>
                        <option value="company" <?php echo $scope==='company'?'selected':''; ?>>Company roles only</option>
                    </select>
                </div>
                <div class="col-lg-3">
                    <label class="form-label">Company</label>
                    <select name="company_id" class="form-select">
                        <option value="0">All companies</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?php echo (int)$company['id']; ?>" <?php echo $companyFilter===(int)$company['id']?'selected':''; ?>><?php echo e($company['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-lg-1 d-flex align-items-end">
                    <button class="btn btn-dark w-100"><i class="bi bi-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="modern-card">
        <div class="modern-card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div><h5 class="card-title">Role List</h5><div class="card-sub"><?php echo $isSuper ? 'System and company roles are clearly separated.' : 'Only roles belonging to your company are shown.'; ?></div></div>
            </div>

            <div class="d-grid gap-3">
                <?php foreach ($roles as $role): ?>
                    <?php
                        $roleId = (int)$role['id'];
                        $roleName = strtolower(trim((string)($role['name'] ?? '')));
                        $isProtected = $roleName === 'superadmin';
                        $isSystemRole = ((int)($role['is_system'] ?? 0) === 1 || empty($role['company_id']));
                    ?>
                    <div class="role-row">
                        <div>
                            <div class="role-title"><?php echo e($role['name']); ?></div>
                            <div class="role-meta">
                                <span class="scope-pill <?php echo $isSystemRole ? 'pill-system' : 'pill-company'; ?>">
                                    <i class="bi <?php echo $isSystemRole ? 'bi-globe2' : 'bi-building'; ?>"></i>
                                    <?php echo $isSystemRole ? 'System Role' : 'Company Role'; ?>
                                </span>
                                <?php if ($isSuper): ?>
                                    <span class="scope-pill pill-company-name"><i class="bi bi-building-check"></i><?php echo e($role['company_name'] ?: 'Global/System'); ?></span>
                                <?php endif; ?>
                                <span class="scope-pill" style="background:#f1f5f9;color:#334155"><i class="bi bi-hash"></i>ID: <?php echo $roleId; ?></span>
                                <span class="scope-pill" style="background:#f8fafc;color:#475569"><i class="bi bi-people"></i>Users: <?php echo (int)($role['users_count'] ?? 0); ?></span>
                            </div>
                        </div>
                        <div class="role-actions">
                            <?php if (can_update('roles')): ?>
                                <a href="permissions.php?role_id=<?php echo $roleId; ?>" class="btn btn-outline-primary"><i class="bi bi-sliders me-1"></i> Permissions</a>
                            <?php endif; ?>

                            <?php if (can_delete('roles') && !$isProtected): ?>
                                <form method="post" onsubmit="return confirm('Delete this role?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <input type="hidden" name="action" value="delete_role">
                                    <input type="hidden" name="role_id" value="<?php echo $roleId; ?>">
                                    <button class="btn btn-outline-danger"><i class="bi bi-trash3 me-1"></i> Delete</button>
                                </form>
                            <?php else: ?>
                                <?php if ($isProtected): ?><span class="text-muted small align-self-center">Protected</span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$roles): ?>
                    <div class="empty-state">No roles found for this scope.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const systemCheck = document.getElementById('is_system');
    const companySelect = document.querySelector('select[name="company_id"]');
    if(systemCheck && companySelect){
        systemCheck.addEventListener('change', function(){
            companySelect.disabled = this.checked;
            if(this.checked) companySelect.value = '0';
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
