No9 Cloud System - Bank Module Install

1. Backup your current database first.
2. Import bank_module.sql in phpMyAdmin.
3. Copy these files into your No9 system root folder:
   - bank_helpers.php
   - banks.php
   - bank_transactions.php
   - bank_statement.php
4. Your config.php must create mysqli variable named: $conn
5. Add sidebar_menu_snippet.php links into your sidebar.
6. In POS/Expense/Invoice payment files:
   - include bank_helpers.php
   - add bank_id select field when payment method is Bank
   - paste the matching snippet from integration_snippets.php after successful save.

Important:
- This module assumes your session has:
  $_SESSION['user_id']
  $_SESSION['company_id']
  $_SESSION['branch_id'] optional
  $_SESSION['role']

If your session names are different, edit bank_helpers.php.
