<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rbac.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;
$success = flash('success');
$prefillUser = trim($_GET['user'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = db()->prepare("\n            SELECT \n                u.*,\n                r.name AS role_name\n            FROM users u\n            LEFT JOIN roles r ON r.id = u.role_id\n            WHERE u.username = ?\n              AND u.status = 'active'\n            LIMIT 1\n        ");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Invalid login details.';
        } elseif (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid login details.';
        } else {
            $roleName = trim((string)($user['role_name'] ?? ''));
            if ($roleName === '') {
                $roleName = trim((string)($user['role'] ?? 'cashier'));
                if ($roleName === '') {
                    $roleName = 'cashier';
                }
            }

            $_SESSION['user'] = [
                'id'         => (int)($user['id'] ?? 0),
                'company_id' => (int)($user['company_id'] ?? 0),
                'branch_id'  => (int)($user['branch_id'] ?? 0),
                'username'   => $user['username'] ?? '',
                'name'       => $user['full_name'] ?? ($user['username'] ?? ''),
                'full_name'  => $user['full_name'] ?? '',
                'role_id'    => (int)($user['role_id'] ?? 0),
                'role'       => $roleName,
                'email'      => $user['email'] ?? '',
                'status'     => $user['status'] ?? 'active',
            ];

            $_SESSION['branch_id'] = (int)($user['branch_id'] ?? 0);
            unset($_SESSION['permissions']);

            flash('success', 'Welcome back, ' . ($user['full_name'] ?? $user['username']));
            redirect('dashboard.php');
        }
    }

    $prefillUser = $username;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>No9 Cloud System - Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<style>
    :root{
        --primary:#2563eb;
        --primary-dark:#1d4ed8;
        --success:#16a34a;
        --teal:#0f766e;
        --dark:#0f172a;
        --muted:#64748b;
        --line:#dbe3ee;
        --soft:#f4f7fb;
    }
    *{box-sizing:border-box}
    body{
        min-height:100vh;
        margin:0;
        font-family:Arial, Helvetica, sans-serif;
        background:
            radial-gradient(circle at top left, rgba(37,99,235,.18), transparent 34%),
            radial-gradient(circle at bottom right, rgba(15,118,110,.16), transparent 36%),
            linear-gradient(180deg,#eaf2ff 0%,#f7fbff 48%,#ffffff 100%);
        display:flex;
        align-items:center;
        justify-content:center;
        padding:24px;
        color:var(--dark);
    }
    .login-card{
        width:100%;
        max-width:520px;
        background:#fff;
        border:1px solid rgba(219,227,238,.95);
        border-radius:28px;
        overflow:hidden;
        box-shadow:0 24px 60px rgba(15,23,42,.14);
    }
    .login-head{
        background:linear-gradient(135deg,var(--primary),var(--primary-dark) 55%,var(--teal));
        padding:34px 30px 28px;
        text-align:center;
        color:#fff;
        position:relative;
    }
    .login-head:before{
        content:"";
        position:absolute;
        inset:0;
        background:radial-gradient(circle at 50% 0%, rgba(255,255,255,.22), transparent 35%);
        pointer-events:none;
    }
    .inventory-icon{
        width:92px;
        height:92px;
        margin:0 auto 14px;
        border-radius:24px;
        display:grid;
        place-items:center;
        background:rgba(255,255,255,.16);
        border:1px solid rgba(255,255,255,.22);
        box-shadow:0 14px 28px rgba(15,23,42,.20);
        position:relative;
        z-index:1;
    }
    .inventory-icon i{
        font-size:44px;
        line-height:1;
    }
    .login-head h1{
        margin:0;
        font-size:28px;
        font-weight:900;
        letter-spacing:.2px;
        position:relative;
        z-index:1;
    }
    .login-head p{
        margin:8px 0 0;
        color:rgba(255,255,255,.86);
        font-size:15px;
        position:relative;
        z-index:1;
    }
    .login-body{
        padding:32px 30px 28px;
    }
    .form-label{
        color:#334155;
        font-weight:800;
        font-size:13px;
        margin-bottom:8px;
    }
    .input-group{
        border:1px solid var(--line);
        border-radius:16px;
        overflow:hidden;
        background:#fff;
        transition:.18s ease;
    }
    .input-group:focus-within{
        border-color:#93c5fd;
        box-shadow:0 0 0 4px rgba(37,99,235,.10);
    }
    .input-group-text{
        border:0;
        background:#f8fafc;
        color:#334155;
        width:50px;
        justify-content:center;
        font-size:18px;
    }
    .form-control{
        border:0;
        min-height:54px;
        box-shadow:none !important;
        color:#0f172a;
    }
    .form-control::placeholder{color:#94a3b8}
    .password-toggle{
        border:0;
        background:#fff;
        color:#334155;
        width:52px;
        font-size:18px;
    }
    .password-toggle:hover{color:var(--primary)}
    .btn-login{
        min-height:56px;
        border:0;
        border-radius:16px;
        font-weight:900;
        font-size:17px;
        background:linear-gradient(135deg,var(--primary),var(--primary-dark) 62%,var(--teal));
        box-shadow:0 14px 28px rgba(37,99,235,.26);
    }
    .btn-login:hover{filter:brightness(.98);transform:translateY(-1px)}
    .mini-row{
        display:flex;
        justify-content:center;
        align-items:center;
        margin-top:18px;
    }
    .mini-link{
        text-decoration:none;
        color:var(--primary);
        font-weight:700;
        font-size:14px;
    }
    .mini-link:hover{color:var(--primary-dark)}
    .login-foot{
        border-top:1px solid #eef2f7;
        padding-top:18px;
        margin-top:24px;
        text-align:center;
        color:#64748b;
        font-size:13px;
    }
    .alert{
        border-radius:16px;
        border:0;
        font-size:14px;
    }
    @media (max-width:576px){
        body{padding:14px}
        .login-card{border-radius:22px}
        .login-head{padding:28px 20px 24px}
        .login-body{padding:26px 20px 22px}
        .login-head h1{font-size:24px}
    }
</style>
</head>
<body>

<div class="login-card">
    <div class="login-head">
        <div class="inventory-icon" aria-hidden="true">
            <i class="bi bi-box-seam-fill"></i>
        </div>
        <h1>No9 Cloud System</h1>
        <p>Inventory • POS • Finance • SaaS</p>
    </div>

    <div class="login-body">
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="post" action="index.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                    <input
                        type="text"
                        name="username"
                        class="form-control"
                        required
                        placeholder="Enter your username"
                        value="<?php echo e($prefillUser); ?>"
                    >
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input
                        type="password"
                        name="password"
                        id="passwordInput"
                        class="form-control"
                        required
                        placeholder="Enter your password"
                    >
                    <button class="password-toggle" type="button" id="togglePassword" aria-label="Show password">
                        <i class="bi bi-eye-fill"></i>
                    </button>
                </div>
            </div>

            <button class="btn btn-primary w-100 btn-login" type="submit">
                <i class="bi bi-box-arrow-in-right me-2"></i> Login
            </button>
        </form>

        <div class="mini-row">
            <a href="forgot_password.php" class="mini-link">Forgot password?</a>
        </div>

        <div class="login-foot">
            © <?php echo date('Y'); ?> No9 Cloud System. All rights reserved.
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('togglePassword');
    const input = document.getElementById('passwordInput');
    if (btn && input) {
        btn.addEventListener('click', function () {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            this.innerHTML = show ? '<i class="bi bi-eye-slash-fill"></i>' : '<i class="bi bi-eye-fill"></i>';
            this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    }
});
</script>

</body>
</html>
