<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

if (!function_exists('is_super_admin') || !is_super_admin()) {
    exit('Only superadmin can run this installer.');
}

$db = db();

$db->query("CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(100) NOT NULL,
    code VARCHAR(150) NOT NULL UNIQUE,
    name VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$db->query("CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY(role_id, permission_id)
)");

$permissions = [
    ['virtual_stock', 'virtual_stock.read', 'Read'],
    ['virtual_stock', 'virtual_stock.export', 'Export'],
    ['stock', 'stock.read', 'Read'],
    ['inventory', 'inventory.read', 'Read'],
];

$added = 0;
foreach ($permissions as $p) {
    [$module, $code, $name] = $p;
    $stmt = $db->prepare("INSERT IGNORE INTO permissions(module, code, name) VALUES(?,?,?)");
    $stmt->bind_param('sss', $module, $code, $name);
    $stmt->execute();
    $added += $stmt->affected_rows > 0 ? 1 : 0;
    $stmt->close();
}

echo '<h3>Virtual Stock permissions installed successfully.</h3>';
echo '<p>Added new permissions: '.(int)$added.'</p>';
echo '<p>Now open Roles & Permissions and tick Virtual Stock Read for the role you want.</p>';
echo '<p><a href="roles.php">Go to Roles</a></p>';
