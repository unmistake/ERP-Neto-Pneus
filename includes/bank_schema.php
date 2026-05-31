<?php

declare(strict_types=1);

function ensureBankTransactionsSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS bank_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bank ENUM('bb','santander','itau') NOT NULL,
            transaction_type ENUM('in','out') NOT NULL,
            description VARCHAR(160) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            transaction_date DATE NOT NULL,
            reference VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $columns = [
        'external_id' => "ALTER TABLE bank_transactions ADD COLUMN external_id VARCHAR(160) NULL AFTER bank",
        'source' => "ALTER TABLE bank_transactions ADD COLUMN source ENUM('manual','ofx','csv') NOT NULL DEFAULT 'manual' AFTER external_id",
        'reconciled' => "ALTER TABLE bank_transactions ADD COLUMN reconciled TINYINT(1) NOT NULL DEFAULT 0 AFTER reference",
        'raw_payload' => "ALTER TABLE bank_transactions ADD COLUMN raw_payload LONGTEXT NULL AFTER reconciled",
        'sale_id' => "ALTER TABLE bank_transactions ADD COLUMN sale_id INT NULL AFTER raw_payload",
        'cost_id' => "ALTER TABLE bank_transactions ADD COLUMN cost_id INT NULL AFTER sale_id",
        'accounts_receivable_id' => "ALTER TABLE bank_transactions ADD COLUMN accounts_receivable_id INT NULL AFTER cost_id",
        'accounts_payable_id' => "ALTER TABLE bank_transactions ADD COLUMN accounts_payable_id INT NULL AFTER accounts_receivable_id",
    ];

    foreach ($columns as $column => $sql) {
        $exists = (bool) $pdo->query("SHOW COLUMNS FROM bank_transactions LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }

    $indexes = $pdo->query("SHOW INDEX FROM bank_transactions WHERE Key_name = 'uk_bank_external_source'")->fetchAll();
    if (count($indexes) === 0) {
        $pdo->exec('ALTER TABLE bank_transactions ADD UNIQUE KEY uk_bank_external_source (bank, external_id, source)');
    }
}
