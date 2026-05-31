<?php
require_once __DIR__ . '/../config/config.php';

if (!function_exists('finance_escape')) {
    function finance_escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($amount): string {
        return '$' . number_format((float)$amount, 2);
    }
}

if (!function_exists('finance_fetch_value')) {
    function finance_fetch_value(mysqli $conn, string $sql, string $types = '', array $params = [], $default = 0) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return $default;

        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_row() : null;
        $stmt->close();

        return $row && isset($row[0]) && $row[0] !== null ? $row[0] : $default;
    }
}

if (!function_exists('finance_branch_where')) {
    function finance_branch_where(int $companyId, $branchId = null): array {
        $where = " company_id = ? ";
        $types = "i";
        $params = [$companyId];

        if (!empty($branchId) && (int)$branchId > 0) {
            $where .= " AND branch_id = ? ";
            $types .= "i";
            $params[] = (int)$branchId;
        }

        return [$where, $types, $params];
    }
}

if (!function_exists('finance_table_exists')) {
    function finance_table_exists(mysqli $conn, string $table): bool {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '$table'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('finance_column_exists')) {
    function finance_column_exists(mysqli $conn, string $table, string $column): bool {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('finance_sum_safe')) {
    function finance_sum_safe(mysqli $conn, string $table, string $sumExpression, int $companyId, $branchId = null, string $extraWhere = ''): float {
        if (!finance_table_exists($conn, $table)) {
            return 0.0;
        }

        [$where, $types, $params] = finance_branch_where($companyId, $branchId);

        $sql = "
            SELECT COALESCE(SUM($sumExpression),0) AS total
            FROM `$table`
            WHERE $where
            $extraWhere
        ";

        return (float)finance_fetch_value($conn, $sql, $types, $params, 0);
    }
}

if (!function_exists('finance_account_id')) {
    function finance_account_id(mysqli $conn, string $code): ?int {
        if (!finance_table_exists($conn, 'chart_of_accounts')) return null;

        $stmt = $conn->prepare("SELECT id FROM chart_of_accounts WHERE code = ? LIMIT 1");
        if (!$stmt) return null;

        $stmt->bind_param('s', $code);
        $stmt->execute();

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        $stmt->close();

        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('finance_create_journal_entry')) {
    function finance_create_journal_entry(mysqli $conn, array $entry, array $lines): int {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO journal_entries
                (entry_date, reference_no, memo, source_module, source_id, branch_id, company_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $sourceId  = $entry['source_id'] ?? null;
            $branchId  = $entry['branch_id'] ?? null;
            $companyId = $entry['company_id'] ?? null;
            $createdBy = $entry['created_by'] ?? null;

            $stmt->bind_param(
                'ssssiiii',
                $entry['entry_date'],
                $entry['reference_no'],
                $entry['memo'],
                $entry['source_module'],
                $sourceId,
                $branchId,
                $companyId,
                $createdBy
            );

            $stmt->execute();
            $journalId = (int)$conn->insert_id;
            $stmt->close();

            $lineStmt = $conn->prepare("
                INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit, credit, line_description)
                VALUES (?, ?, ?, ?, ?)
            ");

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($lines as $line) {
                if (empty($line['account_id'])) {
                    continue;
                }

                $debit  = (float)($line['debit'] ?? 0);
                $credit = (float)($line['credit'] ?? 0);

                $lineStmt->bind_param(
                    'iidds',
                    $journalId,
                    $line['account_id'],
                    $debit,
                    $credit,
                    $line['line_description']
                );

                $lineStmt->execute();

                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            $lineStmt->close();

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                throw new Exception('Journal entry is not balanced.');
            }

            $conn->commit();
            return $journalId;

        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('finance_post_sale')) {
    function finance_post_sale(mysqli $conn, array $sale): int {
        $cashAccount      = finance_account_id($conn, '1000');
        $salesAccount     = finance_account_id($conn, '4000');
        $cogsAccount      = finance_account_id($conn, '5000');
        $inventoryAccount = finance_account_id($conn, '1200');

        $gross = (float)$sale['gross_amount'];
        $cost  = (float)$sale['cost_amount'];

        return finance_create_journal_entry($conn, [
            'entry_date'    => $sale['entry_date'],
            'reference_no'  => $sale['reference_no'] ?? null,
            'memo'          => $sale['memo'] ?? 'POS sale posted automatically',
            'source_module' => 'sales',
            'source_id'     => $sale['source_id'] ?? null,
            'branch_id'     => $sale['branch_id'] ?? null,
            'company_id'    => $sale['company_id'] ?? null,
            'created_by'    => $sale['created_by'] ?? null,
        ], [
            [
                'account_id' => $cashAccount,
                'debit' => $gross,
                'credit' => 0,
                'line_description' => 'Cash received from sale',
            ],
            [
                'account_id' => $salesAccount,
                'debit' => 0,
                'credit' => $gross,
                'line_description' => 'Sales revenue',
            ],
            [
                'account_id' => $cogsAccount,
                'debit' => $cost,
                'credit' => 0,
                'line_description' => 'Cost of goods sold',
            ],
            [
                'account_id' => $inventoryAccount,
                'debit' => 0,
                'credit' => $cost,
                'line_description' => 'Inventory reduced',
            ],
        ]);
    }
}

if (!function_exists('finance_post_expense')) {
    function finance_post_expense(mysqli $conn, array $expense): int {
        $cashAccount    = finance_account_id($conn, '1000');
        $expenseAccount = $expense['expense_account_id'] ?? finance_account_id($conn, '6000');

        return finance_create_journal_entry($conn, [
            'entry_date'    => $expense['entry_date'],
            'reference_no'  => $expense['reference_no'] ?? null,
            'memo'          => $expense['memo'] ?? 'Expense posted automatically',
            'source_module' => 'expenses',
            'source_id'     => $expense['source_id'] ?? null,
            'branch_id'     => $expense['branch_id'] ?? null,
            'company_id'    => $expense['company_id'] ?? null,
            'created_by'    => $expense['created_by'] ?? null,
        ], [
            [
                'account_id' => $expenseAccount,
                'debit' => (float)$expense['amount'],
                'credit' => 0,
                'line_description' => 'Expense debit',
            ],
            [
                'account_id' => $cashAccount,
                'debit' => 0,
                'credit' => (float)$expense['amount'],
                'line_description' => 'Cash paid',
            ],
        ]);
    }
}

if (!function_exists('finance_trial_balance')) {
    function finance_trial_balance(mysqli $conn, ?string $dateFrom = null, ?string $dateTo = null, int $companyId = 0, $branchId = null): array {
        if (!finance_table_exists($conn, 'chart_of_accounts')) return [];

        $where = ' WHERE 1=1 ';
        $types = '';
        $params = [];

        if ($companyId > 0) {
            $where .= ' AND je.company_id = ? ';
            $types .= 'i';
            $params[] = $companyId;
        }

        if (!empty($branchId) && (int)$branchId > 0) {
            $where .= ' AND je.branch_id = ? ';
            $types .= 'i';
            $params[] = (int)$branchId;
        }

        if ($dateFrom) {
            $where .= ' AND je.entry_date >= ? ';
            $types .= 's';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND je.entry_date <= ? ';
            $types .= 's';
            $params[] = $dateTo;
        }

        $sql = "
            SELECT coa.id, coa.code, coa.name, coa.type,
                   COALESCE(SUM(jel.debit),0) AS total_debit,
                   COALESCE(SUM(jel.credit),0) AS total_credit
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON je.id = jel.journal_entry_id
            $where
            GROUP BY coa.id, coa.code, coa.name, coa.type
            ORDER BY coa.code ASC
        ";

        $stmt = $conn->prepare($sql);

        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();

        $res = $stmt->get_result();
        $rows = [];

        while ($row = $res->fetch_assoc()) {
            $row['total_debit'] = (float)($row['total_debit'] ?? 0);
            $row['total_credit'] = (float)($row['total_credit'] ?? 0);
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    }
}

if (!function_exists('finance_net_balance')) {
    function finance_net_balance(array $row): float {
        $normalDebitTypes = ['asset', 'expense', 'cogs'];

        if (in_array($row['type'], $normalDebitTypes, true)) {
            return (float)$row['total_debit'] - (float)$row['total_credit'];
        }

        return (float)$row['total_credit'] - (float)$row['total_debit'];
    }
}

if (!function_exists('fs_balance_sheet')) {
    function fs_balance_sheet(int $companyId, $branchId = null): array {
        $conn = db();

        /*
        |--------------------------------------------------------------------------
        | All Branches:
        | $branchId = null ama 0 => branch filter lama saarayo
        | $branchId > 0 => branch gaar ah
        |--------------------------------------------------------------------------
        */

        $cash = 0;
        $bank = 0;
        $inventory = 0;
        $equipment = 0;
        $receivables = 0;
        $loans = 0;
        $payables = 0;
        $unpaidExpenses = 0;
        $ownerCapital = 0;
        $retainedEarnings = 0;

        /*
        |--------------------------------------------------------------------------
        | CASH
        |--------------------------------------------------------------------------
        */
        if (finance_table_exists($conn, 'cash_transactions')) {
            $cash = finance_sum_safe($conn, 'cash_transactions', 'amount', $companyId, $branchId);
        } elseif (finance_table_exists($conn, 'sales')) {
            $cash = finance_sum_safe($conn, 'sales', 'total_amount', $companyId, $branchId);
            $expenseCashOut = finance_sum_safe($conn, 'expenses', 'amount', $companyId, $branchId);
            $cash -= $expenseCashOut;
        }

        /*
        |--------------------------------------------------------------------------
        | BANK
        |--------------------------------------------------------------------------
        */
        if (finance_table_exists($conn, 'banks')) {
            if (finance_column_exists($conn, 'banks', 'balance')) {
                $bank = finance_sum_safe($conn, 'banks', 'balance', $companyId, $branchId);
            } elseif (finance_column_exists($conn, 'banks', 'opening_balance')) {
                $bank = finance_sum_safe($conn, 'banks', 'opening_balance', $companyId, $branchId);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | INVENTORY
        |--------------------------------------------------------------------------
        */
        if (finance_table_exists($conn, 'products')) {
            $qtyCol = finance_column_exists($conn, 'products', 'stock_qty') ? 'stock_qty' :
                (finance_column_exists($conn, 'products', 'stock') ? 'stock' :
                (finance_column_exists($conn, 'products', 'qty') ? 'qty' : '0'));

            $priceCol = finance_column_exists($conn, 'products', 'cost_price') ? 'cost_price' :
                (finance_column_exists($conn, 'products', 'buying_price') ? 'buying_price' :
                (finance_column_exists($conn, 'products', 'purchase_price') ? 'purchase_price' : '0'));

            $inventory = finance_sum_safe(
                $conn,
                'products',
                "COALESCE($qtyCol,0) * COALESCE($priceCol,0)",
                $companyId,
                $branchId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RECEIVABLES
        |--------------------------------------------------------------------------
        */
        if (finance_table_exists($conn, 'invoices')) {
            if (finance_column_exists($conn, 'invoices', 'due_amount')) {
                $receivables = finance_sum_safe($conn, 'invoices', 'due_amount', $companyId, $branchId);
            } elseif (finance_column_exists($conn, 'invoices', 'balance')) {
                $receivables = finance_sum_safe($conn, 'invoices', 'balance', $companyId, $branchId);
            } elseif (finance_column_exists($conn, 'invoices', 'total_amount')) {
                $receivables = finance_sum_safe($conn, 'invoices', 'total_amount', $companyId, $branchId, " AND status != 'paid' ");
            }
        }

        /*
        |--------------------------------------------------------------------------
        | LIABILITIES
        |--------------------------------------------------------------------------
        */
        if (finance_table_exists($conn, 'loans')) {
            $loans = finance_sum_safe($conn, 'loans', 'amount', $companyId, $branchId);
        }

        if (finance_table_exists($conn, 'payables')) {
            $payables = finance_sum_safe($conn, 'payables', 'amount', $companyId, $branchId);
        }

        if (finance_table_exists($conn, 'expenses')) {
            if (finance_column_exists($conn, 'expenses', 'payment_status')) {
                $unpaidExpenses = finance_sum_safe($conn, 'expenses', 'amount', $companyId, $branchId, " AND payment_status = 'unpaid' ");
            } elseif (finance_column_exists($conn, 'expenses', 'status')) {
                $unpaidExpenses = finance_sum_safe($conn, 'expenses', 'amount', $companyId, $branchId, " AND status = 'unpaid' ");
            }
        }

        /*
        |--------------------------------------------------------------------------
        | EQUITY
        |--------------------------------------------------------------------------
        */
        if (finance_table_exists($conn, 'owners_equity')) {
            if (finance_column_exists($conn, 'owners_equity', 'amount')) {
                $ownerCapital = finance_sum_safe($conn, 'owners_equity', 'amount', $companyId, $branchId);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | RETAINED EARNINGS
        |--------------------------------------------------------------------------
        */
        $salesTotal = finance_table_exists($conn, 'sales')
            ? finance_sum_safe($conn, 'sales', 'total_amount', $companyId, $branchId)
            : 0;

        $expensesTotal = finance_table_exists($conn, 'expenses')
            ? finance_sum_safe($conn, 'expenses', 'amount', $companyId, $branchId)
            : 0;

        $cogsTotal = 0;

        if (finance_table_exists($conn, 'sale_items') && finance_table_exists($conn, 'sales') && finance_table_exists($conn, 'products')) {
            $hasBranch = !empty($branchId) && (int)$branchId > 0;

            $where = " s.company_id = ? ";
            $types = "i";
            $params = [$companyId];

            if ($hasBranch) {
                $where .= " AND s.branch_id = ? ";
                $types .= "i";
                $params[] = (int)$branchId;
            }

            $costCol = finance_column_exists($conn, 'products', 'cost_price') ? 'cost_price' :
                (finance_column_exists($conn, 'products', 'buying_price') ? 'buying_price' :
                (finance_column_exists($conn, 'products', 'purchase_price') ? 'purchase_price' : '0'));

            $sql = "
                SELECT COALESCE(SUM(si.qty * COALESCE(p.$costCol,0)),0)
                FROM sale_items si
                INNER JOIN sales s ON s.id = si.sale_id
                LEFT JOIN products p ON p.id = si.product_id
                WHERE $where
            ";

            $cogsTotal = (float)finance_fetch_value($conn, $sql, $types, $params, 0);
        }

        $retainedEarnings = $salesTotal - $cogsTotal - $expensesTotal;

        /*
        |--------------------------------------------------------------------------
        | TOTALS
        |--------------------------------------------------------------------------
        */
        $assetsTotal = $cash + $bank + $inventory + $equipment + $receivables;
        $liabilitiesTotal = $loans + $payables + $unpaidExpenses;

        if ($ownerCapital <= 0) {
            $ownerCapital = $assetsTotal - $liabilitiesTotal - $retainedEarnings;
        }

        $equityTotal = $ownerCapital + $retainedEarnings;
        $formulaTotal = $liabilitiesTotal + $equityTotal;

        return [
            'assets' => [
                'cash' => $cash,
                'bank' => $bank,
                'inventory' => $inventory,
                'equipment' => $equipment,
                'receivables' => $receivables,
                'total' => $assetsTotal,
            ],

            'liabilities' => [
                'loans' => $loans,
                'payables' => $payables,
                'unpaid_expenses' => $unpaidExpenses,
                'total' => $liabilitiesTotal,
            ],

            'equity' => [
                'owner_capital' => $ownerCapital,
                'retained_earnings' => $retainedEarnings,
                'total' => $equityTotal,
            ],

            'formula_total' => $formulaTotal,
        ];
    }
}