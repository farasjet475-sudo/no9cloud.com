<?php
require_once __DIR__ . '/../config/config.php';
// No9 Cloud System - Bank helper functions
// Include after config/db connection and session start.
// Required: $conn = mysqli connection.

if (!function_exists('no9_current_company_id')) {
    function no9_current_company_id(): int {
        return (int)($_SESSION['company_id'] ?? 0);
    }
}

if (!function_exists('no9_current_branch_id')) {
    function no9_current_branch_id(): ?int {
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
            return null;
        }
        $branchId = $_SESSION['branch_id'] ?? null;
        return $branchId !== null && $branchId !== '' ? (int)$branchId : null;
    }
}

if (!function_exists('no9_current_user_id')) {
    function no9_current_user_id(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

if (!function_exists('bank_post_transaction')) {
    /**
     * Posts a bank transaction and updates bank current_balance.
     *
     * Types increasing balance:
     * deposit, transfer_in, sale, invoice_payment, adjustment
     *
     * Types decreasing balance:
     * withdraw, transfer_out, expense
     */
    function bank_post_transaction(mysqli $conn, array $data): array {
        $required = ['company_id','bank_id','type','amount','transaction_date'];
        foreach ($required as $key) {
            if (!isset($data[$key]) || $data[$key] === '') {
                return ['ok' => false, 'message' => "Missing required field: {$key}"];
            }
        }

        $companyId = (int)$data['company_id'];
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' ? (int)$data['branch_id'] : null;
        $bankId = (int)$data['bank_id'];
        $type = trim($data['type']);
        $amount = (float)$data['amount'];
        $currency = $data['currency'] ?? 'USD';
        $reference = $data['reference'] ?? null;
        $description = $data['description'] ?? null;
        $transactionDate = $data['transaction_date'];
        $relatedTable = $data['related_table'] ?? null;
        $relatedId = isset($data['related_id']) && $data['related_id'] !== '' ? (int)$data['related_id'] : null;
        $createdBy = isset($data['created_by']) && $data['created_by'] !== '' ? (int)$data['created_by'] : null;

        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'Amount must be greater than zero.'];
        }

        $increaseTypes = ['deposit','transfer_in','sale','invoice_payment','adjustment'];
        $decreaseTypes = ['withdraw','transfer_out','expense'];

        if (!in_array($type, array_merge($increaseTypes, $decreaseTypes), true)) {
            return ['ok' => false, 'message' => 'Invalid bank transaction type.'];
        }

        $conn->begin_transaction();

        try {
            $check = $conn->prepare("SELECT id, current_balance FROM banks WHERE id=? AND company_id=? FOR UPDATE");
            $check->bind_param("ii", $bankId, $companyId);
            $check->execute();
            $bank = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$bank) {
                throw new Exception('Bank account not found.');
            }

            $sign = in_array($type, $increaseTypes, true) ? 1 : -1;
            $newBalance = (float)$bank['current_balance'] + ($sign * $amount);

            $stmt = $conn->prepare("
                INSERT INTO bank_transactions
                (company_id, branch_id, bank_id, type, amount, currency, reference, description, transaction_date, related_table, related_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiisdsssssii",
                $companyId,
                $branchId,
                $bankId,
                $type,
                $amount,
                $currency,
                $reference,
                $description,
                $transactionDate,
                $relatedTable,
                $relatedId,
                $createdBy
            );
            $stmt->execute();
            $transactionId = $stmt->insert_id;
            $stmt->close();

            $update = $conn->prepare("UPDATE banks SET current_balance=? WHERE id=? AND company_id=?");
            $update->bind_param("dii", $newBalance, $bankId, $companyId);
            $update->execute();
            $update->close();

            $conn->commit();

            return ['ok' => true, 'transaction_id' => $transactionId, 'new_balance' => $newBalance];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
?>
