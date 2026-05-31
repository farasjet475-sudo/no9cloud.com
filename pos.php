<?php
$pageTitle = 'POS';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/finance_helpers.php';
require_once __DIR__ . '/includes/stock_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = function_exists('enforce_sales_branch')
    ? enforce_sales_branch(current_branch_id())
    : current_branch_id();

$db = db();

if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

function pos_cart_totals(array $cart, float $tax = 0): array {
    $subtotal = 0;
    $discount = 0;

    foreach ($cart as $item) {
        $subtotal += (float)($item['line_total'] ?? 0);
        $discount += (float)($item['discount'] ?? 0);
    }

    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'tax'      => $tax,
        'total'    => $subtotal + $tax,
    ];
}

function pos_table_exists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$table'");
    return $q && $q->num_rows > 0;
}

function pos_column_exists(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function pos_customer_name_from_list(array $customers, ?int $customerId): string {
    if (!$customerId) return 'Walk-in';
    foreach ($customers as $c) {
        if ((int)$c['id'] === (int)$customerId) return $c['name'];
    }
    return 'Walk-in';
}

function pos_logo_url(string $logo): string {
    $logo = trim($logo);
    if ($logo === '') return '';

    $candidates = [
        'uploads/products/' . $logo,
        'uploads/' . $logo,
        $logo,
    ];

    foreach ($candidates as $path) {
        $full = __DIR__ . '/' . ltrim($path, '/');
        if (file_exists($full)) return $path;
    }

    return $logo;
}

function pos_branch_name(mysqli $db, int $branchId): string {
    if ($branchId <= 0) return '';
    $stmt = $db->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['name'] ?? '';
}

function pos_company_branding_safe(): array {
    if (function_exists('company_branding')) {
        $b = company_branding();
        return [
            'name'            => $b['name'] ?? 'Company',
            'title'           => $b['title'] ?? ($b['name'] ?? 'Company'),
            'logo'            => $b['logo'] ?? '',
            'tagline'         => $b['tagline'] ?? '',
            'email'           => $b['email'] ?? '',
            'phone'           => $b['phone'] ?? '',
            'address'         => $b['address'] ?? '',
            'invoice_footer'  => $b['invoice_footer'] ?? 'Thank you for your business.',
            'currency_symbol' => $b['currency_symbol'] ?? '$',
        ];
    }

    return [
        'name'            => 'Company',
        'title'           => 'Company',
        'logo'            => '',
        'tagline'         => '',
        'email'           => '',
        'phone'           => '',
        'address'         => '',
        'invoice_footer'  => 'Thank you for your business.',
        'currency_symbol' => '$',
    ];
}

function pos_render_print_layout(array $doc, array $branding, string $docType = 'Quotation'): void {
    $companyName    = $branding['name'] ?? 'Company';
    $companyTitle   = $branding['title'] ?? $companyName;
    $companyPhone   = $branding['phone'] ?? '';
    $companyAddress = $branding['address'] ?? '';
    $companyEmail   = $branding['email'] ?? '';
    $companyLogo    = pos_logo_url($branding['logo'] ?? '');
    $companyTagline = $branding['tagline'] ?? '';
    $footerText     = $branding['invoice_footer'] ?? 'Thank you for your business.';
    $currency       = $branding['currency_symbol'] ?? '$';

    $totals = pos_cart_totals($doc['items'] ?? [], (float)($doc['tax'] ?? 0));
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?php echo e($docType); ?> - <?php echo e($doc['doc_no'] ?? ''); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            *{box-sizing:border-box}
            html,body{margin:0;padding:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif}
            body{padding:24px}
            .page{max-width:980px;margin:0 auto}
            .sheet{background:#fff;border:1px solid #dbe3ee;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.08)}
            .topline{height:8px;background:#7c3aed}
            .inner{padding:30px}
            .header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;border-bottom:2px solid #eef2f7;padding-bottom:20px}
            .brand{display:flex;gap:16px;align-items:flex-start}
            .brand-logo{width:84px;height:84px;border:1px solid #e5e7eb;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#fff;overflow:hidden}
            .brand-logo img{max-width:100%;max-height:100%;object-fit:contain}
            .brand-text h1{margin:0;font-size:28px;line-height:1.15;color:#0f172a;font-weight:800}
            .brand-text .title{margin-top:4px;font-size:15px;color:#334155;font-weight:700}
            .brand-text .tagline{margin-top:6px;color:#64748b;font-size:13px}
            .brand-meta{margin-top:10px;color:#475569;font-size:13px;line-height:1.6}
            .doc-badge{text-align:right}
            .doc-badge .type{display:inline-block;padding:10px 18px;border-radius:999px;color:#fff;font-weight:800;letter-spacing:.7px;background:#7c3aed;font-size:13px;text-transform:uppercase}
            .doc-badge .number{margin-top:12px;font-size:24px;font-weight:800;color:#0f172a}
            .section-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px}
            .info-card{border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px;padding:16px}
            .info-card h3{margin:0 0 10px;font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#64748b}
            .info-card .line{margin:5px 0;font-size:14px;color:#0f172a}
            table{width:100%;border-collapse:collapse;margin-top:24px}
            thead th{background:#0f172a;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.4px;padding:12px 10px;text-align:left}
            tbody td{padding:12px 10px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#0f172a}
            tbody tr:nth-child(even){background:#fcfdff}
            .right{text-align:right}
            .totals-wrap{display:flex;justify-content:flex-end;margin-top:22px}
            .totals{width:360px;border:1px solid #dbe3ee;background:#f8fafc;border-radius:14px;padding:16px 18px}
            .totals .row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed #cbd5e1;font-size:14px}
            .totals .row:last-child{border-bottom:0}
            .totals .grand{font-size:18px;font-weight:800;color:#0f172a}
            .notes{margin-top:22px;border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px;padding:16px}
            .notes h3{margin:0 0 8px;font-size:13px;color:#334155}
            .footer{margin-top:26px;padding-top:18px;border-top:2px solid #eef2f7;display:flex;justify-content:space-between;gap:20px;align-items:flex-start;color:#475569;font-size:13px;line-height:1.6}
            .signature{min-width:220px;text-align:center}
            .signature-line{margin-top:34px;border-top:1px solid #94a3b8;padding-top:6px;color:#334155}
            .actions{display:flex;gap:12px;margin-top:18px}
            .btn{appearance:none;border:0;border-radius:12px;padding:12px 18px;font-weight:700;text-decoration:none;cursor:pointer;color:#fff;background:#334155}
            .btn-primary{background:#7c3aed}
            @media (max-width:760px){
                body{padding:10px}
                .header,.footer{flex-direction:column}
                .section-grid{grid-template-columns:1fr}
                .totals{width:100%}
            }
            @media print{
                body{background:#fff;padding:0}
                .page{max-width:none}
                .sheet{border:0;border-radius:0;box-shadow:none}
                .actions{display:none}
            }
        </style>
    </head>
    <body>
    <div class="page">
        <div class="sheet">
            <div class="topline"></div>
            <div class="inner">
                <div class="header">
                    <div class="brand">
                        <?php if ($companyLogo): ?>
                            <div class="brand-logo"><img src="<?php echo e($companyLogo); ?>" alt="Logo"></div>
                        <?php endif; ?>
                        <div class="brand-text">
                            <h1><?php echo e($companyName); ?></h1>
                            <div class="title"><?php echo e($companyTitle); ?></div>
                            <?php if ($companyTagline): ?><div class="tagline"><?php echo e($companyTagline); ?></div><?php endif; ?>
                            <div class="brand-meta">
                                <?php if ($companyAddress): ?><div><?php echo e($companyAddress); ?></div><?php endif; ?>
                                <?php if ($companyPhone): ?><div><?php echo e($companyPhone); ?></div><?php endif; ?>
                                <?php if ($companyEmail): ?><div><?php echo e($companyEmail); ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="doc-badge">
                        <div class="type"><?php echo e($docType); ?></div>
                        <div class="number"><?php echo e($doc['doc_no'] ?? ''); ?></div>
                    </div>
                </div>

                <div class="section-grid">
                    <div class="info-card">
                        <h3>Document Information</h3>
                        <div class="line"><strong>Date:</strong> <?php echo e($doc['doc_date'] ?? ''); ?></div>
                        <?php if (!empty($doc['branch_name'])): ?><div class="line"><strong>Branch:</strong> <?php echo e($doc['branch_name']); ?></div><?php endif; ?>
                    </div>
                    <div class="info-card">
                        <h3>Customer Information</h3>
                        <div class="line"><strong>Customer:</strong> <?php echo e($doc['customer_name'] ?? 'Walk-in'); ?></div>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width:60px">#</th>
                            <th>Product</th>
                            <th style="width:120px">Barcode</th>
                            <th class="right" style="width:90px">Qty</th>
                            <th class="right" style="width:130px">Unit Price</th>
                            <th class="right" style="width:130px">Discount</th>
                            <th class="right" style="width:140px">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($doc['items'] ?? []) as $i => $it): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo e($it['product_name'] ?? ''); ?></td>
                            <td><?php echo e($it['barcode'] ?? ''); ?></td>
                            <td class="right"><?php echo number_format((float)($it['qty'] ?? 0), 2); ?></td>
                            <td class="right"><?php echo e($currency) . number_format((float)($it['selling_price'] ?? 0), 2); ?></td>
                            <td class="right"><?php echo e($currency) . number_format((float)($it['discount'] ?? 0), 2); ?></td>
                            <td class="right"><?php echo e($currency) . number_format((float)($it['line_total'] ?? 0), 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="totals-wrap">
                    <div class="totals">
                        <div class="row"><span>Subtotal</span><strong><?php echo e($currency) . number_format($totals['subtotal'], 2); ?></strong></div>
                        <div class="row"><span>Tax</span><strong><?php echo e($currency) . number_format($totals['tax'], 2); ?></strong></div>
                        <div class="row grand"><span>Grand Total</span><span><?php echo e($currency) . number_format($totals['total'], 2); ?></span></div>
                    </div>
                </div>

                <?php if (!empty($doc['notes'])): ?>
                    <div class="notes">
                        <h3>Notes</h3>
                        <div><?php echo nl2br(e($doc['notes'])); ?></div>
                    </div>
                <?php endif; ?>

                <div class="footer">
                    <div>
                        <div><strong><?php echo e($companyName); ?></strong></div>
                        <div><?php echo e($footerText); ?></div>
                        <div>This quotation is valid for 7 days from the issue date.</div>
                    </div>
                    <div class="signature">
                        <div class="signature-line">Authorized Signature</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="actions">
        <button class="btn btn-primary" onclick="window.print()">Print Quotation</button>
        <a href="pos.php" class="btn">Back to POS</a>
    </div>
    </body>
    </html>
    <?php
}

$hasInvoices     = pos_table_exists($db, 'invoices');
$hasInvoiceItems = pos_table_exists($db, 'invoice_items');
$hasDueDate      = $hasInvoices && pos_column_exists($db, 'invoices', 'due_date');
$hasStatus       = $hasInvoices && pos_column_exists($db, 'invoices', 'status');
$hasPaidAmount   = $hasInvoices && pos_column_exists($db, 'invoices', 'paid_amount');
$hasDueAmount    = $hasInvoices && pos_column_exists($db, 'invoices', 'due_amount');
$hasQuotations   = pos_table_exists($db, 'quotations');

$stmt = $db->prepare("SELECT id, name FROM customers WHERE company_id = ? AND branch_id = ? ORDER BY name");
$stmt->bind_param('ii', $cid, $bid);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$branding = pos_company_branding_safe();
$currentBranchName = pos_branch_name($db, (int)$bid);

foreach ($_SESSION['pos_cart'] as $k => $item) {
    $productId = (int)($item['product_id'] ?? 0);
    $stmt = $db->prepare("SELECT id FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
    $stmt->bind_param('iii', $productId, $cid, $bid);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ok) unset($_SESSION['pos_cart'][$k]);
}
$_SESSION['pos_cart'] = array_values($_SESSION['pos_cart']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $stmt = $db->prepare("SELECT p.*, COALESCE(p.cost_price,0) AS cost_price, COALESCE(p.barcode, p.code, '') AS barcode_text FROM products p WHERE p.id = ? AND p.company_id = ? AND p.branch_id = ? LIMIT 1");
        $stmt->bind_param('iii', $productId, $cid, $bid);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$product) { flash('error', 'Product not found in your branch.'); redirect('pos.php'); }
        if ((float)$product['stock_qty'] <= 0) { flash('error', 'Selected product is out of stock.'); redirect('pos.php'); }

        $found = false;
        foreach ($_SESSION['pos_cart'] as $k => $item) {
            if ((int)$item['product_id'] === $productId) {
                $newQty = (float)$item['qty'] + 1;
                if ($newQty > (float)$product['stock_qty']) { flash('error', 'Combined quantity exceeds available stock.'); redirect('pos.php'); }
                $_SESSION['pos_cart'][$k]['qty'] = $newQty;
                $_SESSION['pos_cart'][$k]['stock_qty'] = (float)$product['stock_qty'];
                $_SESSION['pos_cart'][$k]['line_total'] = ($newQty * (float)$_SESSION['pos_cart'][$k]['selling_price']) - (float)$_SESSION['pos_cart'][$k]['discount'];
                if ($_SESSION['pos_cart'][$k]['line_total'] < 0) $_SESSION['pos_cart'][$k]['line_total'] = 0;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $_SESSION['pos_cart'][] = [
                'product_id'     => (int)$product['id'],
                'product_name'   => $product['name'],
                'barcode'        => $product['barcode_text'],
                'stock_qty'      => (float)$product['stock_qty'],
                'buying_price'   => (float)$product['cost_price'],
                'selling_price'  => (float)$product['price'],
                'qty'            => 1,
                'discount'       => 0,
                'line_total'     => (float)$product['price'],
            ];
        }

        flash('success', 'Product added to POS table.');
        redirect('pos.php');
    }

    if ($action === 'update_item') {
        $index        = (int)($_POST['cart_index'] ?? -1);
        $qty          = max(1, (float)($_POST['qty'] ?? 1));
        $sellingPrice = max(0, (float)($_POST['selling_price'] ?? 0));
        $discount     = max(0, (float)($_POST['discount'] ?? 0));

        if (!isset($_SESSION['pos_cart'][$index])) { flash('error', 'Cart item not found.'); redirect('pos.php'); }

        $item = $_SESSION['pos_cart'][$index];
        $productId = (int)$item['product_id'];
        $stmt = $db->prepare("SELECT id, stock_qty FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
        $stmt->bind_param('iii', $productId, $cid, $bid);
        $stmt->execute();
        $fresh = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fresh) { flash('error', 'Product no longer exists in your branch.'); redirect('pos.php'); }
        if ($qty > (float)$fresh['stock_qty']) { flash('error', 'Quantity exceeds available stock.'); redirect('pos.php'); }
        if ($sellingPrice <= 0) $sellingPrice = (float)$item['selling_price'];

        $lineTotal = ($qty * $sellingPrice) - $discount;
        if ($lineTotal < 0) $lineTotal = 0;

        $_SESSION['pos_cart'][$index]['qty'] = $qty;
        $_SESSION['pos_cart'][$index]['selling_price'] = $sellingPrice;
        $_SESSION['pos_cart'][$index]['discount'] = $discount;
        $_SESSION['pos_cart'][$index]['stock_qty'] = (float)$fresh['stock_qty'];
        $_SESSION['pos_cart'][$index]['line_total'] = $lineTotal;

        flash('success', 'Item updated.');
        redirect('pos.php');
    }

    if ($action === 'remove_item') {
        $index = (int)($_POST['cart_index'] ?? -1);
        if (isset($_SESSION['pos_cart'][$index])) {
            unset($_SESSION['pos_cart'][$index]);
            $_SESSION['pos_cart'] = array_values($_SESSION['pos_cart']);
            flash('success', 'Item removed.');
        }
        redirect('pos.php');
    }

    if ($action === 'clear_cart') {
        $_SESSION['pos_cart'] = [];
        flash('success', 'POS table cleared.');
        redirect('pos.php');
    }

    if ($action === 'save_receipt') {
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
        $saleDate   = $_POST['sale_date'] ?? date('Y-m-d');
        $invoiceNo  = trim($_POST['invoice_no'] ?? ('REC-' . date('Ymd-His')));
        $tax        = (float)($_POST['tax'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');

        if (empty($_SESSION['pos_cart'])) { flash('error', 'POS table is empty.'); redirect('pos.php'); }

        $totals = pos_cart_totals($_SESSION['pos_cart'], $tax);
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("INSERT INTO sales(company_id,branch_id,customer_id,invoice_no,sale_date,subtotal,tax,total_amount,notes) VALUES(?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iiissddds', $cid, $bid, $customerId, $invoiceNo, $saleDate, $totals['subtotal'], $tax, $totals['total'], $notes);
            $stmt->execute();
            $saleId = $stmt->insert_id;
            $stmt->close();

            $totalCost = 0;
            foreach ($_SESSION['pos_cart'] as $item) {
                $productId   = (int)$item['product_id'];
                $productName = $item['product_name'];
                $qty         = (float)$item['qty'];
                $unitPrice   = (float)$item['selling_price'];
                $lineTotal   = (float)$item['line_total'];
                $costPrice   = (float)$item['buying_price'];

                $stmt = $db->prepare("SELECT id, stock_qty FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
                $stmt->bind_param('iii', $productId, $cid, $bid);
                $stmt->execute();
                $fresh = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$fresh || (float)$fresh['stock_qty'] < $qty) throw new Exception('Not enough stock for ' . $productName);

                $stmt2 = $db->prepare("INSERT INTO sale_items(sale_id,product_id,product_name,qty,unit_price,line_total) VALUES(?,?,?,?,?,?)");
                $stmt2->bind_param('iisddd', $saleId, $productId, $productName, $qty, $unitPrice, $lineTotal);
                $stmt2->execute();
                $stmt2->close();

                if (!stock_deduct($db, $productId, $qty)) throw new Exception('Failed to deduct stock for ' . $productName);

                if (function_exists('stock_record_movement')) {
                    stock_record_movement($db, [
                        'company_id'       => $cid,
                        'branch_id'        => $bid,
                        'product_id'       => $productId,
                        'transaction_type' => 'RECEIPT_SALE',
                        'reference_no'     => $invoiceNo,
                        'qty_in'           => 0,
                        'qty_out'          => $qty,
                        'unit_cost'        => $costPrice,
                        'notes'            => 'Automatic stock deduction from POS receipt sale',
                        'created_by'       => $_SESSION['user']['id'] ?? null,
                    ]);
                }

                $totalCost += ($costPrice * $qty);
            }

            if (function_exists('finance_post_sale')) {
                finance_post_sale($db, [
                    'entry_date'   => $saleDate,
                    'reference_no' => $invoiceNo,
                    'memo'         => 'Automatic posting from POS receipt sale',
                    'source_id'    => $saleId,
                    'branch_id'    => $bid,
                    'company_id'   => $cid,
                    'created_by'   => $_SESSION['user']['id'] ?? null,
                    'gross_amount' => $totals['total'],
                    'cost_amount'  => $totalCost,
                ]);
            }

            $db->commit();
            $_SESSION['pos_cart'] = [];
            flash('success', 'Receipt saved successfully.');
            redirect('pos.php');
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Receipt failed: ' . $e->getMessage());
            redirect('pos.php');
        }
    }

    if ($action === 'save_invoice') {
        if (!$hasInvoices || !$hasInvoiceItems) { flash('error', 'Invoice tables are missing.'); redirect('pos.php'); }

        $customerId  = (int)($_POST['customer_id'] ?? 0);
        $invoiceDate = $_POST['sale_date'] ?? date('Y-m-d');
        $invoiceNo   = trim($_POST['invoice_no'] ?? ('INV-' . date('Ymd-His')));
        $dueDate     = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
        $tax         = (float)($_POST['tax'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');

        if ($customerId <= 0) { flash('error', 'Select customer for invoice.'); redirect('pos.php'); }
        if (empty($_SESSION['pos_cart'])) { flash('error', 'POS table is empty.'); redirect('pos.php'); }

        $totals = pos_cart_totals($_SESSION['pos_cart'], $tax);
        $db->begin_transaction();
        try {
            $createdBy  = $_SESSION['user']['id'] ?? null;
            $dueAmount  = $totals['total'];
            $paidAmount = 0;
            $status     = 'unpaid';

            if ($hasDueDate && $hasStatus && $hasPaidAmount && $hasDueAmount) {
                $stmt = $db->prepare("INSERT INTO invoices (company_id,branch_id,customer_id,invoice_no,invoice_date,due_date,subtotal,tax,total_amount,paid_amount,due_amount,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iiisssddddddsi', $cid, $bid, $customerId, $invoiceNo, $invoiceDate, $dueDate, $totals['subtotal'], $tax, $totals['total'], $paidAmount, $dueAmount, $status, $notes, $createdBy);
            } else {
                $stmt = $db->prepare("INSERT INTO invoices (company_id,branch_id,customer_id,invoice_no,invoice_date,subtotal,tax,total_amount,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iiissdddsi', $cid, $bid, $customerId, $invoiceNo, $invoiceDate, $totals['subtotal'], $tax, $totals['total'], $notes, $createdBy);
            }
            $stmt->execute();
            $invoiceId = $stmt->insert_id;
            $stmt->close();

            $totalCost = 0;
            foreach ($_SESSION['pos_cart'] as $item) {
                $productId    = (int)$item['product_id'];
                $productName  = $item['product_name'];
                $qty          = (float)$item['qty'];
                $buyingPrice  = (float)$item['buying_price'];
                $sellingPrice = (float)$item['selling_price'];
                $discount     = (float)$item['discount'];
                $lineTotal    = (float)$item['line_total'];

                $stmt = $db->prepare("SELECT id, stock_qty FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
                $stmt->bind_param('iii', $productId, $cid, $bid);
                $stmt->execute();
                $fresh = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$fresh || (float)$fresh['stock_qty'] < $qty) throw new Exception('Not enough stock for ' . $productName);

                $stmt2 = $db->prepare("INSERT INTO invoice_items (invoice_id,product_id,product_name,qty,buying_price,selling_price,discount,line_total) VALUES (?,?,?,?,?,?,?,?)");
                $stmt2->bind_param('iisddddd', $invoiceId, $productId, $productName, $qty, $buyingPrice, $sellingPrice, $discount, $lineTotal);
                $stmt2->execute();
                $stmt2->close();

                if (!stock_deduct($db, $productId, $qty)) throw new Exception('Failed to deduct stock for ' . $productName);

                if (function_exists('stock_record_movement')) {
                    stock_record_movement($db, [
                        'company_id'       => $cid,
                        'branch_id'        => $bid,
                        'product_id'       => $productId,
                        'transaction_type' => 'INVOICE_SALE',
                        'reference_no'     => $invoiceNo,
                        'qty_in'           => 0,
                        'qty_out'          => $qty,
                        'unit_cost'        => $buyingPrice,
                        'notes'            => 'Automatic stock deduction from POS credit invoice',
                        'created_by'       => $_SESSION['user']['id'] ?? null,
                    ]);
                }

                $totalCost += ($buyingPrice * $qty);
            }

            if (function_exists('finance_post_sale')) {
                finance_post_sale($db, [
                    'entry_date'   => $invoiceDate,
                    'reference_no' => $invoiceNo,
                    'memo'         => 'Automatic posting from POS credit invoice',
                    'source_id'    => $invoiceId,
                    'branch_id'    => $bid,
                    'company_id'   => $cid,
                    'created_by'   => $_SESSION['user']['id'] ?? null,
                    'gross_amount' => $totals['total'],
                    'cost_amount'  => $totalCost,
                ]);
            }

            $db->commit();
            $_SESSION['pos_cart'] = [];
            flash('success', 'Invoice saved successfully.');
            redirect('pos.php');
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Invoice failed: ' . $e->getMessage());
            redirect('pos.php');
        }
    }

    if ($action === 'print_quotation') {
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
        $quoteDate  = $_POST['sale_date'] ?? date('Y-m-d');
        $quoteNo    = trim($_POST['invoice_no'] ?? ('QT-' . date('Ymd-His')));
        $tax        = (float)($_POST['tax'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');
        $customerName = pos_customer_name_from_list($customers, $customerId);

        if (empty($_SESSION['pos_cart'])) {
            flash('error', 'POS table is empty.');
            redirect('pos.php');
        }

        $quotationItems = [];
        $amount = 0;
        foreach ($_SESSION['pos_cart'] as $it) {
            $line = (float)($it['line_total'] ?? 0);
            $quotationItems[] = [
                'description' => $it['product_name'] ?? '',
                'barcode'     => $it['barcode'] ?? '',
                'qty'         => (float)($it['qty'] ?? 0),
                'price'       => (float)($it['selling_price'] ?? 0),
                'discount'    => (float)($it['discount'] ?? 0),
                'total'       => $line,
            ];
            $amount += $line;
        }

        if ($hasQuotations) {
            $status = 'draft';
            $details = json_encode([
                'notes' => $notes,
                'tax'   => $tax,
                'items' => $quotationItems,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $db->prepare("INSERT INTO quotations(company_id,branch_id,customer_name,quote_no,quote_date,amount,status,details) VALUES(?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iisssdss', $cid, $bid, $customerName, $quoteNo, $quoteDate, $amount, $status, $details);
            $stmt->execute();
            $quotationId = (int)$stmt->insert_id;
            $stmt->close();

            flash('success', 'Quotation prepared successfully. Stock was not changed.');
            redirect('quotation_print.php?id=' . $quotationId);
        }

        $_SESSION['pos_quotation'] = [
            'doc_no'        => $quoteNo,
            'doc_date'      => $quoteDate,
            'customer_id'   => $customerId,
            'customer_name' => $customerName,
            'tax'           => $tax,
            'notes'         => $notes,
            'items'         => $_SESSION['pos_cart'],
            'branch_id'     => $bid,
            'branch_name'   => $currentBranchName,
        ];
        flash('success', 'Quotation prepared successfully. Stock was not changed.');
        redirect('pos.php?print_quote=1');
    }
}

$stmt = $db->prepare("SELECT id,name,price,stock_qty,COALESCE(cost_price,0) AS cost_price,COALESCE(barcode, code, '') AS barcode,sku,brand,category,unit,branch_id FROM products WHERE company_id = ? AND branch_id = ? AND stock_qty > 0 ORDER BY name");
$stmt->bind_param('ii', $cid, $bid);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$productJson = json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cart = $_SESSION['pos_cart'];
$cartTotals = pos_cart_totals($cart, 0);

if (isset($_GET['print_quote']) && !empty($_SESSION['pos_quotation'])) {
    $q = $_SESSION['pos_quotation'];
    if ((int)($q['branch_id'] ?? 0) !== (int)$bid) { flash('error', 'Quotation does not belong to your branch.'); redirect('pos.php'); }
    pos_render_print_layout([
        'doc_no'        => $q['doc_no'] ?? '',
        'doc_date'      => $q['doc_date'] ?? '',
        'customer_name' => $q['customer_name'] ?? 'Walk-in',
        'notes'         => $q['notes'] ?? '',
        'tax'           => (float)($q['tax'] ?? 0),
        'branch_name'   => $q['branch_name'] ?? $currentBranchName,
        'items'         => $q['items'] ?? [],
    ], $branding, 'Quotation');
    exit;
}
?>
<style>
:root{
    --pos-bg:#f4f7fb;
    --pos-card:#ffffff;
    --pos-text:#0f172a;
    --pos-muted:#64748b;
    --pos-line:#e2e8f0;
    --pos-primary:#2563eb;
    --pos-primary-dark:#1d4ed8;
    --pos-success:#16a34a;
    --pos-danger:#dc2626;
    --pos-warning:#f59e0b;
    --pos-purple:#7c3aed;
    --pos-soft:#eff6ff;
    --pos-soft-2:#f8fafc;
}
.pos-shell{display:grid;gap:20px}

.pos-optional-wrap{display:grid;gap:14px}
.pos-toggle-bar{display:flex;justify-content:flex-end;align-items:center;margin-bottom:2px}
.pos-toggle-btn{border:none;border-radius:14px;padding:10px 16px;font-weight:800;font-size:14px;color:#fff;background:linear-gradient(135deg,#1d4ed8,#0f766e);box-shadow:0 10px 24px rgba(15,23,42,.12);display:inline-flex;align-items:center;gap:10px}
.pos-toggle-btn:hover{opacity:.96;transform:translateY(-1px)}
.pos-toggle-btn .toggle-chevron{transition:transform .25s ease}
.pos-toggle-btn.is-open .toggle-chevron{transform:rotate(180deg)}
.pos-collapse-area{display:grid;gap:20px}
.pos-collapsible{display:none}
.pos-collapsible.show{display:grid}
.pos-top{
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.10), transparent 20%),
        linear-gradient(135deg,#0f172a,#1e3a8a 55%, #0f766e);
    color:#fff;
    border-radius:26px;
    padding:28px;
    box-shadow:0 18px 40px rgba(15,23,42,.18)
}
.pos-top h4{margin:0 0 8px;font-size:30px;font-weight:800}
.pos-top .sub{color:rgba(255,255,255,.84);max-width:760px}
.pos-menu .btn{border-radius:999px;padding:10px 16px;font-weight:700;box-shadow:none}
.pos-menu .btn-primary{background:linear-gradient(90deg,var(--pos-primary),var(--pos-primary-dark));border:0}

.pos-card{
    border:1px solid rgba(226,232,240,.9);
    border-radius:26px;
    background:var(--pos-card);
    box-shadow:0 16px 38px rgba(15,23,42,.06);
}
.pos-card .card-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:18px
}
.pos-card .card-head h5{
    margin:0;
    font-weight:800;
    color:var(--pos-text);
    font-size:1.05rem;
}
.pos-card .card-head p{
    margin:4px 0 0;
    color:var(--pos-muted);
    font-size:14px;
}

.search-box{position:relative}
.search-box .search-icon{
    position:absolute;
    left:18px;
    top:50%;
    transform:translateY(-50%);
    color:#64748b;
    font-size:18px;
    z-index:3;
}
.search-box .form-control{
    height:60px;
    border-radius:20px;
    padding-left:50px;
    padding-right:16px;
    border:1px solid #dbe3ee;
    background:linear-gradient(180deg,#ffffff,#f8fbff);
    box-shadow:0 10px 24px rgba(37,99,235,.05);
    font-size:15px;
}
.search-box .form-control:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 4px rgba(37,99,235,.10), 0 14px 30px rgba(37,99,235,.07);
}

.result-list{
    z-index:1000;
    max-height:360px;
    overflow:auto;
    display:none;
    border-radius:20px;
    border:1px solid #dbe3ee;
    margin-top:10px;
    background:#fff;
    box-shadow:0 18px 40px rgba(15,23,42,.10);
}
.result-list .list-group-item{
    border:0;
    border-bottom:1px solid #eef2f7;
    padding:14px 16px;
    background:#fff;
}
.result-list .list-group-item:last-child{border-bottom:0}
.result-list .list-group-item:hover{background:#f8fbff}
.search-product-name{
    font-weight:800;
    color:#0f172a;
    margin-bottom:4px;
}
.search-product-meta{
    color:#64748b;
    font-size:12px;
    line-height:1.55;
}
.search-stock-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    border-radius:999px;
    padding:4px 10px;
    background:#ecfeff;
    color:#0f766e;
    font-weight:700;
    font-size:11px;
    margin-top:8px;
}

.pos-hint{
    background:#f8fafc;
    border:1px dashed #cbd5e1;
    border-radius:20px;
    padding:15px 16px;
    color:var(--pos-muted)
}
.pos-form .form-control,
.pos-form .form-select{
    border-radius:16px;
    min-height:50px;
    border:1px solid #dbe3ee;
    box-shadow:none;
    background:#fff;
}
.pos-form .form-control:focus,
.pos-form .form-select:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 4px rgba(37,99,235,.08);
}
.pos-form .form-label{
    font-weight:800;
    color:#334155;
    font-size:12px;
    margin-bottom:7px;
    text-transform:uppercase;
    letter-spacing:.3px;
}

.pos-grid-stat{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px
}
.pos-stat{
    border-radius:22px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10)
}
.pos-stat small{opacity:.9;font-size:12px}
.pos-stat strong{display:block;margin-top:8px;font-size:22px}
.pos-s1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.pos-s2{background:linear-gradient(135deg,#10b981,#059669)}
.pos-s3{background:linear-gradient(135deg,#f59e0b,#d97706)}
.pos-s4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}

.pos-table-wrap{
    border:1px solid #dbe3ee;
    border-radius:22px;
    overflow:hidden;
    background:#fff;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.8);
}
.pos-table{margin:0}
.pos-table thead th{
    background:linear-gradient(180deg,#f8fbff,#f1f5f9);
    border-bottom:1px solid #dbe3ee;
    font-size:12px;
    color:#334155;
    white-space:nowrap;
    text-transform:uppercase;
    letter-spacing:.35px;
    font-weight:800;
    padding:16px 14px;
}
.pos-table tbody tr{transition:.18s ease}
.pos-table tbody tr:nth-child(even){background:#fcfdff}
.pos-table tbody tr:hover{background:#f8fbff}
.pos-table td{
    vertical-align:middle;
    padding:14px;
    border-color:#eef2f7;
}
.pos-table input.form-control{
    min-width:90px;
    border-radius:12px;
    min-height:42px;
    font-size:14px;
}
.product-cell-title{
    font-weight:800;
    color:#0f172a;
    margin-bottom:3px;
}
.product-cell-sub{
    color:#64748b;
    font-size:12px;
}
.product-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#eff6ff;
    color:#1d4ed8;
    border-radius:999px;
    padding:6px 11px;
    font-size:12px;
    font-weight:800
}
.stock-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:74px;
    padding:7px 10px;
    border-radius:999px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    font-weight:800;
    color:#334155;
}
.amount-text{
    font-weight:800;
    color:#0f172a;
    white-space:nowrap;
}
.row-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.btn-update-row{
    border:none;
    border-radius:12px;
    padding:9px 12px;
    background:linear-gradient(135deg,#2563eb,#3b82f6);
    color:#fff;
    font-weight:700;
}
.btn-remove-row{
    border:none;
    border-radius:12px;
    padding:9px 12px;
    background:linear-gradient(135deg,#dc2626,#ef4444);
    color:#fff;
    font-weight:700;
}

.money-box{
    border-radius:22px;
    background:linear-gradient(135deg,#eff6ff,#f8fafc);
    border:1px solid #dbeafe;
    padding:18px
}
.money-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:8px 0;
    border-bottom:1px dashed #cbd5e1
}
.money-row:last-child{border-bottom:0}
.money-row.total{
    font-size:18px;
    font-weight:800;
    color:#0f172a
}
.action-stack{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px
}
.action-btn{
    border:none;
    outline:none;
    border-radius:18px;
    padding:16px 18px;
    font-size:15px;
    font-weight:800;
    color:#fff;
    cursor:pointer;
    transition:all .22s ease;
    box-shadow:0 8px 20px rgba(0,0,0,.12);
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    letter-spacing:.2px
}
.action-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 12px 24px rgba(0,0,0,.18);
    opacity:.96
}
.action-btn:active{transform:translateY(0)}
.action-save{background:linear-gradient(135deg,#16a34a,#22c55e)}
.action-invoice{background:linear-gradient(135deg,#2563eb,#3b82f6)}
.action-quote{background:linear-gradient(135deg,#7c3aed,#a855f7)}
.action-clear{background:linear-gradient(135deg,#dc2626,#ef4444)}
.mini-note{font-size:12px;color:var(--pos-muted)}
.pro-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.18);
    font-weight:700;
    font-size:.82rem
}
.empty-table{
    padding:50px 20px !important;
}
.empty-table-box{
    display:inline-block;
    padding:16px 20px;
    border-radius:18px;
    background:#f8fafc;
    border:1px dashed #cbd5e1;
    color:#64748b;
}

@media (max-width:992px){
    .pos-grid-stat{grid-template-columns:repeat(2,minmax(0,1fr))}
    .action-stack{grid-template-columns:1fr}
}
@media (max-width:576px){
    .pos-grid-stat{grid-template-columns:1fr}
}
</style>

<div class="pos-shell">
    <div class="pos-optional-wrap">
        <div class="pos-toggle-bar">
            <button class="pos-toggle-btn" type="button" id="posToggleBtn" aria-expanded="false" aria-controls="posOptionalBlock">
                <i class="bi bi-layout-text-window-reverse"></i>
                <span>Show POS Header</span>
                <i class="bi bi-chevron-down toggle-chevron"></i>
            </button>
        </div>

        <div class="pos-collapsible" id="posOptionalBlock">
            <div class="pos-collapse-area">
                <div class="pos-top">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <div class="pro-badge mb-2">Professional POS Screen</div>
                            <h4>POS Terminal</h4>
                            <div class="sub">
                                Fast, clean, and professional sale screen. Receipt and Invoice are save-only. Quotation is the only printable document.
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap pos-menu">
                            <a href="pos.php" class="btn btn-primary">POS</a>
                        </div>
                    </div>
                </div>

                <div class="pos-grid-stat">
                    <div class="pos-stat pos-s1"><small>Items in Table</small><strong><?php echo count($cart); ?></strong></div>
                    <div class="pos-stat pos-s2"><small>Subtotal</small><strong><?php echo money($cartTotals['subtotal']); ?></strong></div>
                    <div class="pos-stat pos-s3"><small>Discount</small><strong><?php echo money($cartTotals['discount']); ?></strong></div>
                    <div class="pos-stat pos-s4"><small>Total</small><strong><?php echo money($cartTotals['total']); ?></strong></div>
                </div>
            </div>
        </div>
    </div>

    <div class="pos-card p-4">
        <div class="card-head">
            <div>
                <h5>Quick Product Search</h5>
                <p>Only products from your assigned branch appear here.</p>
            </div>
        </div>

        <div class="search-box mb-3">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="productSearch" class="form-control" placeholder="Search product by name, barcode, or SKU...">
            <div id="searchResults" class="list-group position-absolute w-100 result-list"></div>
        </div>

        <div class="pos-hint">
            <div class="fw-bold mb-2">How it works</div>
            <div class="small">
                Search product → click result → item auto-adds to temp table → edit Qty / Selling Price / Discount → Save Receipt / Save Invoice / Print Quotation.
            </div>
        </div>
    </div>

    <div class="pos-card p-4">
        <div class="card-head">
            <div>
                <h5>POS Sale Table</h5>
                <p>Edit quantities, prices, and discounts before final action.</p>
            </div>
        </div>

        <div class="pos-form">
            <form method="post" id="posActionForm">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Document No</label>
                        <input name="invoice_no" class="form-control" value="POS-<?php echo date('Ymd-His'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="sale_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-select">
                            <option value="">Walk-in</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Due Date (Invoice)</label>
                        <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Tax</label>
                        <input type="number" step="0.01" min="0" name="tax" class="form-control" value="0">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional note...">
                    </div>
                </div>
            </form>

            <div class="pos-table-wrap">
                <div class="table-responsive">
                    <table class="table pos-table align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Barcode</th>
                                <th>Stock</th>
                                <th>Buying</th>
                                <th>Selling</th>
                                <th>Qty</th>
                                <th>Discount</th>
                                <th>Total</th>
                                <th width="190">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cart as $i => $item): ?>
                                <tr>
                                    <td><span class="stock-pill"><?php echo $i + 1; ?></span></td>

                                    <td>
                                        <div class="product-cell-title"><?php echo e($item['product_name']); ?></div>
                                        <div class="product-cell-sub">Editable POS row</div>
                                    </td>

                                    <td>
                                        <span class="product-chip">
                                            <i class="bi bi-upc-scan"></i>
                                            <?php echo e($item['barcode']); ?>
                                        </span>
                                    </td>

                                    <td><span class="stock-pill"><?php echo number_format((float)$item['stock_qty'], 2); ?></span></td>

                                    <td class="amount-text"><?php echo number_format((float)$item['buying_price'], 2); ?></td>

                                    <td>
                                        <input form="updateItemForm<?php echo $i; ?>" type="number" step="0.01" min="0" name="selling_price" value="<?php echo e((string)$item['selling_price']); ?>" class="form-control form-control-sm">
                                    </td>

                                    <td>
                                        <input form="updateItemForm<?php echo $i; ?>" type="number" step="0.01" min="1" name="qty" value="<?php echo e((string)$item['qty']); ?>" class="form-control form-control-sm">
                                    </td>

                                    <td>
                                        <input form="updateItemForm<?php echo $i; ?>" type="number" step="0.01" min="0" name="discount" value="<?php echo e((string)$item['discount']); ?>" class="form-control form-control-sm">
                                    </td>

                                    <td class="amount-text"><?php echo number_format((float)$item['line_total'], 2); ?></td>

                                    <td>
                                        <form method="post" id="updateItemForm<?php echo $i; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="action" value="update_item">
                                            <input type="hidden" name="cart_index" value="<?php echo $i; ?>">
                                        </form>
                                        <form method="post" id="removeItemForm<?php echo $i; ?>" onsubmit="return confirm('Remove item?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="action" value="remove_item">
                                            <input type="hidden" name="cart_index" value="<?php echo $i; ?>">
                                        </form>

                                        <div class="row-actions">
                                            <button class="btn-update-row" type="submit" form="updateItemForm<?php echo $i; ?>">
                                                <i class="bi bi-check2-circle me-1"></i> Update
                                            </button>
                                            <button class="btn-remove-row" type="submit" form="removeItemForm<?php echo $i; ?>">
                                                <i class="bi bi-trash3 me-1"></i> Remove
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$cart): ?>
                                <tr>
                                    <td colspan="10" class="text-center empty-table">
                                        <div class="empty-table-box">
                                            No products added yet. Search and click a product to start.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row mt-4 g-4">
                <div class="col-md-6">
                    <div class="money-box">
                        <div class="money-row"><span>Subtotal</span><strong><?php echo money($cartTotals['subtotal']); ?></strong></div>
                        <div class="money-row"><span>Discount</span><strong><?php echo money($cartTotals['discount']); ?></strong></div>
                        <div class="money-row"><span>Tax</span><strong><?php echo money($cartTotals['tax']); ?></strong></div>
                        <div class="money-row total"><span>Grand Total</span><span><?php echo money($cartTotals['total']); ?></span></div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="action-stack">
                        <button form="posActionForm" type="submit" name="action" value="save_receipt" class="action-btn action-save confirm-action" data-message="Ma hubtaa inaad rabto inaad kaydiso Receipt-kan?">
                            <span>🧾</span><span>Save Receipt</span>
                        </button>

                        <button form="posActionForm" type="submit" name="action" value="save_invoice" class="action-btn action-invoice confirm-action" data-message="Ma hubtaa inaad rabto inaad kaydiso Invoice-kan? Stock-ga wuu is beddeli karaa.">
                            <span>📄</span><span>Save Invoice</span>
                        </button>

                        <button form="posActionForm" type="submit" name="action" value="print_quotation" class="action-btn action-quote confirm-action" data-message="Ma hubtaa inaad rabto inaad sameyso Quotation? Stock waxba kama badalayo.">
                            <span>🖨️</span><span>Print Quotation</span>
                        </button>

                        <button form="posActionForm" type="submit" name="action" value="clear_cart" class="action-btn action-clear confirm-action" data-message="Dhammaan items-ka miiska ma tirtirtaa?">
                            <span>🗑️</span><span>Clear Table</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<form id="quickAddForm" method="post" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="action" value="add_item">
    <input type="hidden" name="product_id" id="quick_product_id">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const posToggleBtn = document.getElementById('posToggleBtn');
    const posOptionalBlock = document.getElementById('posOptionalBlock');
    if (posToggleBtn && posOptionalBlock) {
        posToggleBtn.addEventListener('click', function () {
            const isOpen = posOptionalBlock.classList.toggle('show');
            this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            this.classList.toggle('is-open', isOpen);
            const label = this.querySelector('span');
            if (label) label.textContent = isOpen ? 'Hide POS Header' : 'Show POS Header';
        });
    }

    document.querySelectorAll('.confirm-action').forEach(function (button) {
        button.addEventListener('click', function (e) {
            const message = this.getAttribute('data-message') || 'Ma hubtaa?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
});

const productsData = <?php echo $productJson; ?>;
const searchInput = document.getElementById('productSearch');
const resultsBox = document.getElementById('searchResults');
const quickProductId = document.getElementById('quick_product_id');
const quickAddForm = document.getElementById('quickAddForm');

function moneyFormat(val) {
    return Number(val).toFixed(2);
}

function renderResults(list) {
    resultsBox.innerHTML = '';
    if (!list.length) {
        resultsBox.style.display = 'none';
        return;
    }

    list.forEach(product => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action text-start';
        item.innerHTML = `
            <div class="search-product-name">${product.name}</div>
            <div class="search-product-meta">
                Barcode: ${product.barcode || '-'} &nbsp;|&nbsp;
                SKU: ${product.sku || '-'} &nbsp;|&nbsp;
                Buy: ${moneyFormat(product.cost_price || 0)} &nbsp;|&nbsp;
                Sell: ${moneyFormat(product.price || 0)}
            </div>
            <div class="search-stock-badge">
                <i class="bi bi-box-seam"></i>
                Stock: ${Number(product.stock_qty).toFixed(2)}
            </div>
        `;
        item.addEventListener('click', function () {
            quickProductId.value = product.id;
            quickAddForm.submit();
        });
        resultsBox.appendChild(item);
    });

    resultsBox.style.display = 'block';
}

searchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    if (q.length < 1) {
        resultsBox.style.display = 'none';
        return;
    }

    const filtered = productsData.filter(product =>
        (product.name && product.name.toLowerCase().includes(q)) ||
        (product.barcode && String(product.barcode).toLowerCase().includes(q)) ||
        (product.sku && String(product.sku).toLowerCase().includes(q))
    ).slice(0, 12);

    renderResults(filtered);
});

document.addEventListener('click', function (e) {
    if (!resultsBox.contains(e.target) && e.target !== searchInput) {
        resultsBox.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>