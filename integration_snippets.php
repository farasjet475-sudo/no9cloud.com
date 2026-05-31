<?php
/*
No9 Bank Module - Integration Snippets
Paste these examples into your POS / expenses / invoice payment save logic.
Make sure you include bank_helpers.php after config.php.
*/

require_once __DIR__ . '/bank_helpers.php';

/*
1) POS SALE INTEGRATION
When finalizing sale:
- If payment_method = bank
- bank_id must come from POS form
*/
if ($payment_method === 'bank' && !empty($_POST['bank_id'])) {
    $bankResult = bank_post_transaction($conn, [
        'company_id' => (int)$_SESSION['company_id'],
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'bank_id' => (int)$_POST['bank_id'],
        'type' => 'sale',
        'amount' => (float)$grand_total,
        'currency' => $currency ?? 'USD',
        'reference' => 'SALE-' . $sale_id,
        'description' => 'POS sale payment received by bank',
        'transaction_date' => date('Y-m-d'),
        'related_table' => 'sales',
        'related_id' => (int)$sale_id,
        'created_by' => (int)$_SESSION['user_id']
    ]);

    if (!$bankResult['ok']) {
        throw new Exception($bankResult['message']);
    }
}

/*
2) EXPENSE INTEGRATION
When saving expense:
- If paid_from = bank
- bank_id must come from expense form
*/
if ($paid_from === 'bank' && !empty($_POST['bank_id'])) {
    $bankResult = bank_post_transaction($conn, [
        'company_id' => (int)$_SESSION['company_id'],
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'bank_id' => (int)$_POST['bank_id'],
        'type' => 'expense',
        'amount' => (float)$expense_amount,
        'currency' => $currency ?? 'USD',
        'reference' => 'EXP-' . $expense_id,
        'description' => 'Expense paid from bank',
        'transaction_date' => $expense_date ?? date('Y-m-d'),
        'related_table' => 'expenses',
        'related_id' => (int)$expense_id,
        'created_by' => (int)$_SESSION['user_id']
    ]);

    if (!$bankResult['ok']) {
        throw new Exception($bankResult['message']);
    }
}

/*
3) INVOICE PAYMENT INTEGRATION
When receiving invoice/debt payment:
- If payment_method = bank
*/
if ($payment_method === 'bank' && !empty($_POST['bank_id'])) {
    $bankResult = bank_post_transaction($conn, [
        'company_id' => (int)$_SESSION['company_id'],
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'bank_id' => (int)$_POST['bank_id'],
        'type' => 'invoice_payment',
        'amount' => (float)$paid_amount,
        'currency' => $currency ?? 'USD',
        'reference' => 'INV-PAY-' . $payment_id,
        'description' => 'Invoice payment received by bank',
        'transaction_date' => $payment_date ?? date('Y-m-d'),
        'related_table' => 'invoice_payments',
        'related_id' => (int)$payment_id,
        'created_by' => (int)$_SESSION['user_id']
    ]);

    if (!$bankResult['ok']) {
        throw new Exception($bankResult['message']);
    }
}

/*
4) POS / EXPENSE FORM FIELD
Add this select in your forms where payment method is bank.

<select name="bank_id" class="form-select">
  <option value="">Choose bank</option>
  <?php
  $companyId = (int)$_SESSION['company_id'];
  $banks = $conn->query("SELECT id, bank_name, currency, current_balance FROM banks WHERE company_id={$companyId} AND status='active'");
  while($b = $banks->fetch_assoc()):
  ?>
    <option value="<?= $b['id'] ?>">
      <?= htmlspecialchars($b['bank_name']) ?> - <?= htmlspecialchars($b['currency']) ?> - Bal: <?= number_format($b['current_balance'],2) ?>
    </option>
  <?php endwhile; ?>
</select>
*/
?>
