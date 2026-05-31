<!-- Add this under INVENTORY menu in includes/sidebar.php -->
<?php if (function_exists('can_read') ? (can_read('virtual_stock') || can_read('stock') || can_read('inventory')) : true): ?>
<a href="virtual_stock.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) === 'virtual_stock.php' ? 'active' : ''; ?>">
    <i class="bi bi-layers"></i>
    <span>Virtual Stock</span>
</a>
<?php endif; ?>
