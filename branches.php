<?php
$pageTitle = 'Branches';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) {
    redirect('dashboard.php');
}

if (!is_company_admin()) {
    flash('error', 'Only company admin can manage branches.');
    redirect('dashboard.php');
}

$cid = current_company_id();
$db  = db();

$message = '';
$error = '';

$plan = current_plan();
$maxBranches = (int)($plan['max_branches'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_branch') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            $error = 'Branch name is required.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("
                    UPDATE branches
                    SET name=?, phone=?, email=?, address=?
                    WHERE id=? AND company_id=?
                ");
                $stmt->bind_param('ssssii', $name, $phone, $email, $address, $id, $cid);
                $stmt->execute();
                $stmt->close();

                flash('success', 'Branch updated successfully.');
                redirect('branches.php');
            } else {
                $currentCount = count_row("SELECT COUNT(*) FROM branches WHERE company_id=".(int)$cid);

                if ($maxBranches > 0 && $currentCount >= $maxBranches) {
                    $error = 'Your current plan allows only '.$maxBranches.' branch(es).';
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO branches(company_id, name, phone, email, address)
                        VALUES(?,?,?,?,?)
                    ");
                    $stmt->bind_param('issss', $cid, $name, $phone, $email, $address);
                    $stmt->execute();
                    $stmt->close();

                    flash('success', 'Branch added successfully.');
                    redirect('branches.php');
                }
            }
        }
    }

    if ($action === 'delete_branch') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            if (current_branch_id() === $id) {
                $error = 'You cannot delete the currently active branch.';
            } else {
                $stmt = $db->prepare("DELETE FROM branches WHERE id=? AND company_id=?");
                $stmt->bind_param('ii', $id, $cid);
                $stmt->execute();
                $stmt->close();

                flash('success', 'Branch deleted successfully.');
                redirect('branches.php');
            }
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $stmt = $db->prepare("
        SELECT *
        FROM branches
        WHERE id=? AND company_id=?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $editId, $cid);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$rows = $db->query("
    SELECT *
    FROM branches
    WHERE company_id=".(int)$cid."
    ORDER BY id DESC
")->fetch_all(MYSQLI_ASSOC);

$totalBranches = count($rows);
?>

<style>
:root{
    --saas-navy:#0f172a;
    --saas-navy-2:#111827;
    --saas-blue:#2563eb;
    --saas-blue-2:#1d4ed8;
    --saas-teal:#0f766e;
    --saas-green:#10b981;
    --saas-bg:#f4f7fb;
    --saas-card:#ffffff;
    --saas-line:#e2e8f0;
    --saas-text:#0f172a;
    --saas-muted:#64748b;
    --saas-danger:#dc2626;
}
.branch-page{
    display:grid;
    gap:22px;
    padding:24px;
    background:var(--saas-bg);
}
.branch-hero{
    border-radius:28px;
    padding:28px;
    color:#fff;
    background:
        radial-gradient(circle at top right, rgba(37,99,235,.34), transparent 35%),
        linear-gradient(135deg,var(--saas-navy),var(--saas-navy-2));
    box-shadow:0 18px 42px rgba(15,23,42,.16);
}
.hero-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 13px;
    border-radius:999px;
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.12);
    font-size:12px;
    font-weight:800;
    margin-bottom:12px;
}
.branch-hero h3{margin:0;font-size:30px;font-weight:900;letter-spacing:-.4px}
.branch-hero p{margin:8px 0 0;color:rgba(255,255,255,.80);max-width:760px}
.hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.btn-soft-light{
    border:1px solid rgba(255,255,255,.20);
    background:rgba(255,255,255,.12);
    color:#fff;
    border-radius:14px;
    padding:10px 16px;
    font-weight:800;
    text-decoration:none;
}
.btn-soft-light:hover{background:#fff;color:var(--saas-navy)}
.kpi-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.kpi-card{
    position:relative;
    overflow:hidden;
    border-radius:24px;
    padding:20px;
    background:#fff;
    border:1px solid rgba(226,232,240,.9);
    box-shadow:0 14px 34px rgba(15,23,42,.06);
}
.kpi-card:after{
    content:"";
    position:absolute;
    width:120px;height:120px;
    border-radius:999px;
    right:-42px;top:-48px;
    background:rgba(37,99,235,.10);
}
.kpi-icon{
    width:46px;height:46px;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    background:linear-gradient(135deg,var(--saas-blue),var(--saas-teal));
    box-shadow:0 12px 24px rgba(37,99,235,.20);
    margin-bottom:12px;
}
.kpi-card small{display:block;color:var(--saas-muted);font-weight:800;text-transform:uppercase;letter-spacing:.4px;font-size:11px}
.kpi-card strong{display:block;margin-top:6px;color:var(--saas-text);font-size:28px;font-weight:900}
.branch-layout{display:grid;grid-template-columns:390px 1fr;gap:22px;align-items:start}
.pro-card{
    background:#fff;
    border:1px solid rgba(226,232,240,.92);
    border-radius:26px;
    box-shadow:0 16px 38px rgba(15,23,42,.06);
    overflow:hidden;
}
.pro-card-head{
    padding:22px 24px 14px;
    border-bottom:1px solid #edf2f7;
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
}
.pro-title{font-size:20px;font-weight:900;color:var(--saas-text);margin:0}
.pro-sub{font-size:13px;color:var(--saas-muted);margin:5px 0 0;line-height:1.55}
.pro-card-body{padding:24px}
.form-label{font-size:12px;font-weight:900;color:#334155;text-transform:uppercase;letter-spacing:.35px;margin-bottom:8px}
.form-control{
    min-height:48px;
    border-radius:15px;
    border:1px solid #dbe3ee;
    box-shadow:none;
}
.form-control:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 4px rgba(37,99,235,.09);
}
.branch-id-panel{
    border-radius:18px;
    padding:14px 16px;
    background:linear-gradient(135deg,#eff6ff,#f8fafc);
    border:1px solid #dbeafe;
    margin-bottom:16px;
}
.branch-id-panel .id-label{font-size:11px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.branch-id-panel .id-value{font-size:22px;font-weight:900;color:#1d4ed8;margin-top:2px}
.btn-main{
    min-height:50px;
    border:0;
    border-radius:16px;
    color:#fff;
    font-weight:900;
    background:linear-gradient(135deg,var(--saas-blue),var(--saas-teal));
    box-shadow:0 12px 24px rgba(37,99,235,.18);
}
.btn-main:hover{color:#fff;opacity:.95;transform:translateY(-1px)}
.btn-cancel{border-radius:16px;min-height:48px;font-weight:800}
.table-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.search-mini{max-width:310px;position:relative}
.search-mini i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b}
.search-mini input{padding-left:40px}
.table-wrap{border:1px solid #e2e8f0;border-radius:20px;overflow:hidden;background:#fff}
.branch-table{margin:0}
.branch-table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.35px;
    font-weight:900;
    padding:14px 16px;
}
.branch-table td{padding:15px 16px;vertical-align:middle;border-color:#edf2f7}
.branch-table tbody tr:hover{background:#f8fbff}
.id-badge{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:7px 12px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    border:1px solid #dbeafe;
    font-size:12px;
    font-weight:900;
}
.branch-name-wrap strong{display:block;color:#0f172a;font-weight:900}
.current-pill{
    display:inline-flex;
    align-items:center;
    gap:5px;
    margin-top:5px;
    padding:4px 9px;
    border-radius:999px;
    background:#ecfdf5;
    color:#047857;
    font-size:11px;
    font-weight:900;
}
.contact-muted{color:#64748b;font-size:13px}
.action-group{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.action-btn{border-radius:12px;font-weight:800}
.empty-state{text-align:center;padding:54px 20px;color:#64748b}
.empty-state i{font-size:34px;color:#94a3b8;display:block;margin-bottom:10px}
.alert{border-radius:16px;border:0;box-shadow:0 10px 24px rgba(15,23,42,.05)}
@media (max-width:1100px){.branch-layout{grid-template-columns:1fr}.kpi-grid{grid-template-columns:1fr 1fr}}
@media (max-width:640px){.branch-page{padding:14px}.branch-hero{padding:22px}.kpi-grid{grid-template-columns:1fr}.branch-table th:nth-child(4),.branch-table td:nth-child(4){display:none}}
</style>

<div class="container-fluid branch-page">

    <div class="branch-hero">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="hero-badge"><i class="bi bi-diagram-3"></i> Branch Control</div>
                <h3>Branches Management</h3>
                <p>Manage company branches, view branch IDs clearly, and keep POS / inventory records linked to the correct branch.</p>
            </div>
            <div class="hero-actions">
                <a href="dashboard.php" class="btn-soft-light"><i class="bi bi-arrow-left me-1"></i> Back</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($success = flash('success')): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?php echo e($success); ?></div>
    <?php endif; ?>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-buildings"></i></div>
            <small>Total Branches</small>
            <strong><?php echo (int)$totalBranches; ?></strong>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-layers"></i></div>
            <small>Plan Limit</small>
            <strong><?php echo $maxBranches > 0 ? (int)$maxBranches : 'Unlimited'; ?></strong>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bi bi-geo-alt"></i></div>
            <small>Current Branch ID</small>
            <strong><?php echo (int)current_branch_id(); ?></strong>
        </div>
    </div>

    <div class="branch-layout">
        <div class="pro-card">
            <div class="pro-card-head">
                <div>
                    <h5 class="pro-title"><?php echo $edit ? 'Edit Branch' : 'Add Branch'; ?></h5>
                    <p class="pro-sub">Branch ID is shown clearly for inventory imports and product linking.</p>
                </div>
                <span class="id-badge"><i class="bi bi-hash"></i><?php echo $edit ? (int)$edit['id'] : 'New'; ?></span>
            </div>
            <div class="pro-card-body">
                <div class="branch-id-panel">
                    <div class="id-label"><?php echo $edit ? 'Editing Branch ID' : 'New Branch ID'; ?></div>
                    <div class="id-value"><?php echo $edit ? '#'.(int)$edit['id'] : 'Auto after save'; ?></div>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="save_branch">
                    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

                    <div class="mb-3">
                        <label class="form-label">Branch Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="Example: Main Branch" value="<?php echo e($edit['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="Branch phone" value="<?php echo e($edit['phone'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="branch@email.com" value="<?php echo e($edit['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3" placeholder="Branch address"><?php echo e($edit['address'] ?? ''); ?></textarea>
                    </div>

                    <button class="btn btn-main w-100" type="submit">
                        <i class="bi bi-check2-circle me-1"></i>
                        <?php echo $edit ? 'Update Branch' : 'Save Branch'; ?>
                    </button>

                    <?php if ($edit): ?>
                        <a href="branches.php" class="btn btn-outline-secondary btn-cancel w-100 mt-2">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="pro-card">
            <div class="pro-card-head table-toolbar">
                <div>
                    <h5 class="pro-title">Branch List</h5>
                    <p class="pro-sub">Use the visible Branch ID when fixing product/import branch_id issues.</p>
                </div>
                <div class="search-mini">
                    <i class="bi bi-search"></i>
                    <input type="text" id="branchSearch" class="form-control" placeholder="Search branch...">
                </div>
            </div>
            <div class="pro-card-body">
                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table branch-table align-middle" id="branchTable">
                            <thead>
                                <tr>
                                    <th style="width:110px">Branch ID</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th class="text-end" style="width:190px">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td>
                                            <span class="id-badge"><i class="bi bi-hash"></i><?php echo (int)$r['id']; ?></span>
                                        </td>
                                        <td>
                                            <div class="branch-name-wrap">
                                                <strong><?php echo e($r['name']); ?></strong>
                                                <?php if ((int)$r['id'] === current_branch_id()): ?>
                                                    <span class="current-pill"><i class="bi bi-check-circle"></i> Current branch</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><span class="contact-muted"><?php echo e($r['phone'] ?: '-'); ?></span></td>
                                        <td><span class="contact-muted"><?php echo e($r['email'] ?: '-'); ?></span></td>
                                        <td><span class="contact-muted"><?php echo e($r['address'] ?: '-'); ?></span></td>
                                        <td>
                                            <div class="action-group">
                                                <a href="?edit=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary action-btn">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>

                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this branch?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="delete_branch">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <button class="btn btn-sm btn-outline-danger action-btn" type="submit">
                                                        <i class="bi bi-trash3"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="6" class="empty-state">
                                            <i class="bi bi-buildings"></i>
                                            No branches found. Add your first branch to continue.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('branchSearch');
    const table = document.getElementById('branchTable');
    if (!input || !table) return;

    input.addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
