INSTALLATION
1) Upload virtual_stock.php to your system root folder.
2) Upload install_virtual_stock_permissions.php to root and run it once in browser as superadmin.
3) Add sidebar_virtual_stock_snippet.php code inside includes/sidebar.php under Inventory menu.
4) Go to Roles & Permissions and give the role: virtual_stock.read.
5) Optional: Delete install_virtual_stock_permissions.php after permissions are installed.

HOW IT WORKS
Available Stock = Physical Stock - Pending Transfer Out - Reserved Stock
Forecast Stock  = Physical Stock + Pending Transfer In - Pending Transfer Out - Reserved Stock

This page works with multi-company, multi-branch, stock_transfers, products, roles permissions, and CSV export.
