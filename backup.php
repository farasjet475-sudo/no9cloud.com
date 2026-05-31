<?php
require_once __DIR__ . '/includes/auth.php';

require_login();
$db = db();

/*
|--------------------------------------------------------------------------
| NO9 CLOUD SYSTEM - BACKUP & RESTORE CENTER
|--------------------------------------------------------------------------
| Features:
| - SQL Download Backup
| - One-click Snapshot Backup
| - Automatic Daily Snapshot Backup
| - Restore Center
| - Deleted Records Log table support
| - Company scoped restore for company admins
| - Full platform SQL backup for superadmin
*/

function bk_is_table(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$table'");
    return $q && $q->num_rows > 0;
}

function bk_has_col(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function bk_columns(mysqli $db, string $table): array {
    $cols = [];
    if (!bk_is_table($db, $table)) return $cols;
    $res = $db->query("SHOW COLUMNS FROM `$table`");
    if ($res) while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
    return $cols;
}

function bk_table_pk(mysqli $db, string $table): string {
    if (!bk_is_table($db, $table)) return 'id';
    $res = $db->query("SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if (($r['Key'] ?? '') === 'PRI') return $r['Field'];
        }
    }
    return 'id';
}

function bk_insert_sql(string $table, array $row, mysqli $db): string {
    $cols = array_map(fn($c) => "`" . str_replace('`', '``', $c) . "`", array_keys($row));
    $vals = array_map(function ($v) use ($db) {
        return is_null($v) ? 'NULL' : "'" . $db->real_escape_string((string)$v) . "'";
    }, array_values($row));
    return "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
}

function bk_can_restore(): bool {
    if (function_exists('is_super_admin') && is_super_admin()) return true;
    if (function_exists('is_company_admin') && is_company_admin()) return true;
    if (function_exists('is_admin') && is_admin()) return true;
    if (function_exists('current_user')) {
        $u = current_user();
        $role = strtolower((string)($u['role'] ?? $u['role_name'] ?? ''));
        return in_array($role, ['admin','company_admin','manager','administrator'], true);
    }
    return false;
}

function bk_company_id(): int {
    return function_exists('current_company_id') ? (int)current_company_id() : 0;
}

function bk_user_id(): int {
    return function_exists('current_user_id') ? (int)current_user_id() : 0;
}

function bk_money($amount): string {
    if (function_exists('money')) return money($amount);
    return number_format((float)$amount, 2);
}

function bk_seed_tables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS backup_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NULL,
        scope VARCHAR(30) NOT NULL DEFAULT 'company',
        backup_type VARCHAR(30) NOT NULL DEFAULT 'manual',
        title VARCHAR(180) NULL,
        payload LONGTEXT NOT NULL,
        size_bytes BIGINT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(company_id),
        INDEX(scope),
        INDEX(backup_type),
        INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS deleted_records_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NULL,
        branch_id INT NULL,
        table_name VARCHAR(100) NOT NULL,
        record_id INT NULL,
        record_title VARCHAR(255) NULL,
        record_data LONGTEXT NULL,
        deleted_by INT NULL,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        restored_by INT NULL,
        restored_at DATETIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'deleted',
        INDEX(company_id),
        INDEX(table_name),
        INDEX(record_id),
        INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS backup_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

bk_seed_tables($db);

function bk_company_tables(): array {
    return [
        'companies','branches','users','customers','suppliers','products','expenses','sales','quotations','invoices',
        'payment_proofs','notifications','activity_logs','subscriptions','settings','banks','bank_transactions',
        'stock_transfers','stock_movements','purchases','purchase_payments','purchase_returns','roles'
    ];
}

function bk_child_relations(): array {
    return [
        'sale_items' => ['parent' => 'sales', 'fk' => 'sale_id'],
        'invoice_items' => ['parent' => 'invoices', 'fk' => 'invoice_id'],
        'quotation_items' => ['parent' => 'quotations', 'fk' => 'quotation_id'],
        'purchase_items' => ['parent' => 'purchases', 'fk' => 'purchase_id'],
        'stock_transfer_items' => ['parent' => 'stock_transfers', 'fk' => 'transfer_id'],
    ];
}

function bk_fetch_rows(mysqli $db, string $sql): array {
    $rows = [];
    $res = $db->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

function bk_ids(mysqli $db, string $table, int $cid): array {
    if (!bk_is_table($db, $table) || !bk_has_col($db, $table, 'id')) return [];
    if (bk_has_col($db, $table, 'company_id')) {
        $res = $db->query("SELECT id FROM `$table` WHERE company_id=" . (int)$cid);
    } else {
        return [];
    }
    $ids = [];
    if ($res) while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
    return $ids;
}

function bk_company_payload(int $cid): array {
    $db = db();
    $data = [
        '_meta' => [
            'system' => 'NO9 Cloud System',
            'scope' => 'company',
            'company_id' => $cid,
            'generated_at' => date('Y-m-d H:i:s'),
            'version' => 2,
        ],
        'tables' => []
    ];

    foreach (bk_company_tables() as $table) {
        if (!bk_is_table($db, $table)) continue;

        if ($table === 'companies') {
            if (bk_has_col($db, 'companies', 'id')) {
                $rows = bk_fetch_rows($db, "SELECT * FROM `companies` WHERE id=" . (int)$cid);
            } else continue;
        } elseif (bk_has_col($db, $table, 'company_id')) {
            $rows = bk_fetch_rows($db, "SELECT * FROM `$table` WHERE company_id=" . (int)$cid);
        } else {
            continue;
        }

        $data['tables'][$table] = $rows;
    }

    foreach (bk_child_relations() as $child => $rel) {
        $parent = $rel['parent'];
        $fk = $rel['fk'];
        if (!bk_is_table($db, $child) || !bk_has_col($db, $child, $fk)) continue;
        $parentIds = bk_ids($db, $parent, $cid);
        if (!$parentIds) {
            $data['tables'][$child] = [];
            continue;
        }
        $idList = implode(',', array_map('intval', $parentIds));
        $data['tables'][$child] = bk_fetch_rows($db, "SELECT * FROM `$child` WHERE `$fk` IN ($idList)");
    }

    if (bk_is_table($db, 'role_permissions') && bk_is_table($db, 'roles')) {
        $roleIds = bk_ids($db, 'roles', $cid);
        if ($roleIds) {
            $idList = implode(',', array_map('intval', $roleIds));
            $data['tables']['role_permissions'] = bk_fetch_rows($db, "SELECT * FROM `role_permissions` WHERE role_id IN ($idList)");
        }
    }

    return $data;
}

function bk_create_company_snapshot(int $cid, string $type = 'manual', string $title = ''): int {
    $db = db();
    $payload = bk_company_payload($cid);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new Exception('Unable to encode backup payload.');
    $size = strlen($json);
    $createdBy = bk_user_id();
    if ($title === '') $title = ucfirst($type) . ' backup - ' . date('Y-m-d H:i:s');

    $stmt = $db->prepare("INSERT INTO backup_snapshots (company_id, scope, backup_type, title, payload, size_bytes, created_by) VALUES (?, 'company', ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssii', $cid, $type, $title, $json, $size, $createdBy);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function bk_maybe_auto_daily(int $cid): void {
    if ($cid <= 0) return;
    $db = db();
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT id FROM backup_snapshots WHERE company_id=? AND backup_type='auto_daily' AND DATE(created_at)=? LIMIT 1");
    $stmt->bind_param('is', $cid, $today);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        try { bk_create_company_snapshot($cid, 'auto_daily', 'Auto daily backup - ' . $today); } catch (Throwable $e) {}
    }
}

function bk_delete_company_current_data(mysqli $db, int $cid): void {
    $childOrder = array_keys(bk_child_relations());
    foreach ($childOrder as $child) {
        $rel = bk_child_relations()[$child];
        $parent = $rel['parent'];
        $fk = $rel['fk'];
        if (!bk_is_table($db, $child) || !bk_has_col($db, $child, $fk) || !bk_is_table($db, $parent)) continue;
        $parentIds = bk_ids($db, $parent, $cid);
        if (!$parentIds) continue;
        $idList = implode(',', array_map('intval', $parentIds));
        $db->query("DELETE FROM `$child` WHERE `$fk` IN ($idList)");
    }

    if (bk_is_table($db, 'role_permissions') && bk_is_table($db, 'roles')) {
        $roleIds = bk_ids($db, 'roles', $cid);
        if ($roleIds) {
            $idList = implode(',', array_map('intval', $roleIds));
            $db->query("DELETE FROM `role_permissions` WHERE role_id IN ($idList)");
        }
    }

    $tables = array_reverse(bk_company_tables());
    foreach ($tables as $table) {
        if (!bk_is_table($db, $table)) continue;
        if ($table === 'companies') continue;
        if (bk_has_col($db, $table, 'company_id')) {
            $db->query("DELETE FROM `$table` WHERE company_id=" . (int)$cid);
        }
    }
}

function bk_insert_row(mysqli $db, string $table, array $row): void {
    if (!bk_is_table($db, $table) || !$row) return;
    $existingCols = bk_columns($db, $table);
    $row = array_intersect_key($row, array_flip($existingCols));
    if (!$row) return;

    $cols = array_keys($row);
    $colSql = implode(',', array_map(fn($c) => "`" . str_replace('`', '``', $c) . "`", $cols));
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $values = array_values($row);
    $types = str_repeat('s', count($values));

    $stmt = $db->prepare("INSERT INTO `$table` ($colSql) VALUES ($placeholders)");
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function bk_restore_company_snapshot(int $snapshotId, int $cid): void {
    $db = db();
    $stmt = $db->prepare("SELECT * FROM backup_snapshots WHERE id=? AND company_id=? AND scope='company' LIMIT 1");
    $stmt->bind_param('ii', $snapshotId, $cid);
    $stmt->execute();
    $snap = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$snap) throw new Exception('Backup snapshot not found.');

    $payload = json_decode($snap['payload'], true);
    if (!is_array($payload) || empty($payload['tables'])) throw new Exception('Backup payload is invalid.');

    $db->begin_transaction();
    try {
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        bk_delete_company_current_data($db, $cid);

        $insertOrder = bk_company_tables();
        $insertOrder[] = 'role_permissions';
        foreach (array_keys(bk_child_relations()) as $child) $insertOrder[] = $child;

        foreach ($insertOrder as $table) {
            if (empty($payload['tables'][$table]) || !is_array($payload['tables'][$table])) continue;
            foreach ($payload['tables'][$table] as $row) {
                if (!is_array($row)) continue;
                bk_insert_row($db, $table, $row);
            }
        }
        $db->query("SET FOREIGN_KEY_CHECKS=1");
        $db->commit();
    } catch (Throwable $e) {
        $db->query("SET FOREIGN_KEY_CHECKS=1");
        $db->rollback();
        throw $e;
    }
}

function bk_company_sql(int $cid): string {
    $db = db();
    $payload = bk_company_payload($cid);
    $sql = "-- NO9 Company SQL Backup\n-- Company ID: $cid\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($payload['tables'] as $table => $rows) {
        if (!bk_is_table($db, $table)) continue;
        foreach ($rows as $row) $sql .= bk_insert_sql($table, $row, $db);
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

function bk_full_platform_sql(): string {
    $db = db();
    $tables = [];
    $res = $db->query("SHOW TABLES");
    if ($res) while ($row = $res->fetch_array()) $tables[] = $row[0];
    $sql = "-- NO9 Full Platform Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table) {
        $createRow = $db->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
        $create = $createRow['Create Table'] ?? '';
        $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $create . ";\n\n";
        $rows = $db->query("SELECT * FROM `$table`");
        if ($rows) while ($r = $rows->fetch_assoc()) $sql .= bk_insert_sql($table, $r, $db);
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

/* Download runs before header output */
if (isset($_GET['download'])) {
    if (function_exists('is_super_admin') && is_super_admin()) {
        $sql = bk_full_platform_sql();
        $file = 'no9_full_platform_' . date('Ymd_His') . '.sql';
    } else {
        $cid = bk_company_id();
        $sql = bk_company_sql($cid);
        $file = 'no9_company_' . $cid . '_' . date('Ymd_His') . '.sql';
    }
    if (ob_get_length()) @ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

$cid = bk_company_id();
if (!(function_exists('is_super_admin') && is_super_admin())) bk_maybe_auto_daily($cid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!bk_can_restore()) {
        flash('error', 'Only admins can use backup restore tools.');
        redirect('backup.php');
    }

    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_snapshot') {
        try {
            $title = trim($_POST['title'] ?? 'Manual backup - ' . date('Y-m-d H:i:s'));
            bk_create_company_snapshot($cid, 'manual', $title);
            flash('success', 'Backup snapshot created successfully.');
        } catch (Throwable $e) {
            flash('error', 'Snapshot failed: ' . $e->getMessage());
        }
        redirect('backup.php');
    }

    if ($action === 'restore_snapshot') {
        $snapshotId = (int)($_POST['snapshot_id'] ?? 0);
        $confirm = trim($_POST['confirm_restore'] ?? '');
        if ($confirm !== 'RESTORE') {
            flash('error', 'Type RESTORE to confirm.');
            redirect('backup.php');
        }
        try {
            bk_restore_company_snapshot($snapshotId, $cid);
            flash('success', 'One-click restore completed. Deleted data from that backup should now be back.');
        } catch (Throwable $e) {
            flash('error', 'Restore failed: ' . $e->getMessage());
        }
        redirect('backup.php');
    }

    if ($action === 'restore_sql' && !empty($_FILES['restore_file']['tmp_name'])) {
        $sql = file_get_contents($_FILES['restore_file']['tmp_name']);
        if (!$sql) {
            flash('error', 'Restore file is empty or invalid.');
            redirect('backup.php');
        }
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $db->multi_query($sql);
            while ($db->more_results() && $db->next_result()) {}
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            flash('success', 'SQL restore completed.');
        } catch (Throwable $e) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            flash('error', 'SQL restore failed: ' . $e->getMessage());
        }
        redirect('backup.php');
    }

    if ($action === 'restore_deleted_record') {
        $logId = (int)($_POST['log_id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT * FROM deleted_records_log WHERE id=? AND status='deleted' LIMIT 1");
            $stmt->bind_param('i', $logId);
            $stmt->execute();
            $log = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$log) throw new Exception('Deleted record not found.');
            if (!(function_exists('is_super_admin') && is_super_admin()) && (int)$log['company_id'] !== $cid) throw new Exception('Access denied.');

            $table = (string)$log['table_name'];
            $row = json_decode((string)$log['record_data'], true);
            if (!$table || !is_array($row)) throw new Exception('Deleted record data is invalid.');

            $pk = bk_table_pk($db, $table);
            if (isset($row[$pk])) {
                $safePkVal = $db->real_escape_string((string)$row[$pk]);
                $exists = $db->query("SELECT `$pk` FROM `$table` WHERE `$pk`='$safePkVal' LIMIT 1");
                if ($exists && $exists->num_rows > 0) throw new Exception('This record already exists.');
            }

            bk_insert_row($db, $table, $row);
            $restoredBy = bk_user_id();
            $stmt = $db->prepare("UPDATE deleted_records_log SET status='restored', restored_by=?, restored_at=NOW() WHERE id=?");
            $stmt->bind_param('ii', $restoredBy, $logId);
            $stmt->execute();
            $stmt->close();
            flash('success', 'Deleted record restored successfully.');
        } catch (Throwable $e) {
            flash('error', 'Deleted record restore failed: ' . $e->getMessage());
        }
        redirect('backup.php');
    }
}

$pageTitle = 'Backup & Restore';
require_once __DIR__ . '/includes/header.php';

$snapWhere = function_exists('is_super_admin') && is_super_admin() ? "1=1" : "company_id=" . (int)$cid;
$snapshots = bk_fetch_rows($db, "SELECT * FROM backup_snapshots WHERE $snapWhere ORDER BY id DESC LIMIT 20");
$deletedWhere = function_exists('is_super_admin') && is_super_admin() ? "1=1" : "company_id=" . (int)$cid;
$deletedRows = bk_fetch_rows($db, "SELECT * FROM deleted_records_log WHERE $deletedWhere ORDER BY id DESC LIMIT 50");
?>

<style>
.backup-shell{display:grid;gap:22px}.hero-backup{border-radius:28px;padding:30px;color:#fff;background:linear-gradient(135deg,#0f172a,#1d4ed8 55%,#0f766e);box-shadow:0 20px 48px rgba(15,23,42,.16)}.hero-backup h2{margin:0;font-weight:900}.hero-backup p{margin:8px 0 0;color:rgba(255,255,255,.84)}.soft-card{border:1px solid #e2e8f0;border-radius:24px;background:#fff;box-shadow:0 14px 34px rgba(15,23,42,.07)}.card-pad{padding:24px}.stat-card{border-radius:20px;padding:18px;color:#fff;height:100%;box-shadow:0 10px 24px rgba(0,0,0,.10)}.stat-card small{opacity:.9;font-weight:700}.stat-card h3{margin:8px 0 0;font-weight:900}.bg-a{background:linear-gradient(135deg,#2563eb,#1d4ed8)}.bg-b{background:linear-gradient(135deg,#10b981,#059669)}.bg-c{background:linear-gradient(135deg,#f59e0b,#d97706)}.bg-d{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}.section-title{font-weight:900;color:#0f172a;margin-bottom:5px}.section-sub{color:#64748b;font-size:14px;margin-bottom:16px}.btn{border-radius:14px;font-weight:800}.form-control{border-radius:14px;min-height:46px}.table thead th{background:#f8fafc;color:#334155;white-space:nowrap}.badge-soft{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:800;font-size:12px}.danger-note{border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:16px;padding:12px 14px;font-weight:700}.info-note{border:1px solid #bae6fd;background:#f0f9ff;color:#075985;border-radius:16px;padding:12px 14px;font-weight:700}.mini-code{font-family:monospace;background:#f1f5f9;border-radius:8px;padding:2px 6px}
</style>

<div class="container-fluid py-4 backup-shell">
    <div class="hero-backup">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Backup, Restore Center & Recycle Bin</h2>
                <p>Download SQL backups, create one-click snapshots, restore deleted data, and keep automatic daily backups for No9 Cloud System.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>
    <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?php echo e($msg); ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-md-3"><div class="stat-card bg-a"><small>Snapshots</small><h3><?php echo count($snapshots); ?></h3></div></div>
        <div class="col-md-3"><div class="stat-card bg-b"><small>Access</small><h3><?php echo (function_exists('is_super_admin') && is_super_admin()) ? 'Platform' : 'Company'; ?></h3></div></div>
        <div class="col-md-3"><div class="stat-card bg-c"><small>Deleted Logs</small><h3><?php echo count($deletedRows); ?></h3></div></div>
        <div class="col-md-3"><div class="stat-card bg-d"><small>Daily Backup</small><h3>Auto</h3></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="soft-card card-pad h-100">
                <h5 class="section-title">SQL Backup</h5>
                <div class="section-sub">Download a .sql file. Superadmin gets full platform; company admin gets company scope.</div>
                <a href="backup.php?download=1" class="btn btn-primary"><i class="bi bi-download me-1"></i> Download SQL Backup</a>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="soft-card card-pad h-100">
                <h5 class="section-title">Create Snapshot Backup</h5>
                <div class="section-sub">This is the best option for one-click restore when data is deleted.</div>
                <form method="post" class="d-flex gap-2 flex-wrap">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_snapshot">
                    <input name="title" class="form-control flex-grow-1" value="Manual backup - <?php echo date('Y-m-d H:i'); ?>">
                    <button class="btn btn-success"><i class="bi bi-database-add me-1"></i> Create Snapshot</button>
                </form>
            </div>
        </div>
    </div>

    <div class="soft-card card-pad">
        <h5 class="section-title">One-Click Restore Center</h5>
        <div class="section-sub">Choose a snapshot created before the delete happened, type <span class="mini-code">RESTORE</span>, then restore. This brings deleted company data back from that snapshot.</div>
        <div class="danger-note mb-3">Restore snapshot will replace current company data with the selected backup state. Create a new snapshot before restoring.</div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>ID</th><th>Title</th><th>Type</th><th>Date</th><th>Size</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($snapshots as $s): ?>
                    <tr>
                        <td><strong>#<?php echo (int)$s['id']; ?></strong></td>
                        <td><?php echo e($s['title'] ?? 'Backup'); ?></td>
                        <td><span class="badge-soft"><?php echo e($s['backup_type']); ?></span></td>
                        <td><?php echo e($s['created_at']); ?></td>
                        <td><?php echo number_format(((float)$s['size_bytes']) / 1024, 1); ?> KB</td>
                        <td>
                            <form method="post" class="d-flex gap-2 flex-wrap" onsubmit="return confirm('Restore this backup snapshot? Current company data will be replaced.');">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="restore_snapshot">
                                <input type="hidden" name="snapshot_id" value="<?php echo (int)$s['id']; ?>">
                                <input name="confirm_restore" class="form-control form-control-sm" style="max-width:130px" placeholder="Type RESTORE">
                                <button class="btn btn-sm btn-danger">Restore</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$snapshots): ?><tr><td colspan="6" class="text-center text-muted py-4">No backup snapshots found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="soft-card card-pad">
        <h5 class="section-title">Recycle Bin / Deleted Records Log</h5>
        <div class="section-sub">This section restores records logged inside <span class="mini-code">deleted_records_log</span>. For best results, update delete buttons in products/customers/sales to log records before delete.</div>
        <div class="info-note mb-3">If this list is empty, use Snapshot Restore above. Snapshot restore brings back data even when delete pages did not log the deleted record.</div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Date</th><th>Table</th><th>Record</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($deletedRows as $r): ?>
                    <tr>
                        <td><?php echo e($r['deleted_at']); ?></td>
                        <td><span class="badge-soft"><?php echo e($r['table_name']); ?></span></td>
                        <td><?php echo e($r['record_title'] ?: ('Record #' . $r['record_id'])); ?></td>
                        <td><?php echo e($r['status']); ?></td>
                        <td>
                            <?php if (($r['status'] ?? '') === 'deleted'): ?>
                                <form method="post" onsubmit="return confirm('Restore this deleted record?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <input type="hidden" name="action" value="restore_deleted_record">
                                    <input type="hidden" name="log_id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="btn btn-sm btn-success">Restore Record</button>
                                </form>
                            <?php else: ?>
                                <span class="text-success fw-bold small">Restored</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deletedRows): ?><tr><td colspan="5" class="text-center text-muted py-4">No deleted records logged yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="soft-card card-pad">
        <h5 class="section-title">Manual SQL Restore</h5>
        <div class="section-sub">Use this only when you want to import a .sql backup file manually.</div>
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="restore_sql">
            <div class="col-md-8"><input type="file" name="restore_file" class="form-control" accept=".sql" required></div>
            <div class="col-md-4"><button class="btn btn-outline-danger w-100">Restore SQL File</button></div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
