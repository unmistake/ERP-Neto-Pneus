<?php

declare(strict_types=1);

function ensureSaleFiscalSchema(PDO $pdo): void
{
    $hasSellerName = (bool) $pdo->query("SHOW COLUMNS FROM sales LIKE 'seller_name'")->fetch();
    if (!$hasSellerName) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN seller_name VARCHAR(40) NULL AFTER customer_name");
    }

    $hasFiscalType = (bool) $pdo->query("SHOW COLUMNS FROM sales LIKE 'fiscal_document_type'")->fetch();
    if (!$hasFiscalType) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN fiscal_document_type ENUM('none','nfe') NOT NULL DEFAULT 'none' AFTER payment_status");
    }

    $hasFiscalStatus = (bool) $pdo->query("SHOW COLUMNS FROM sales LIKE 'fiscal_status'")->fetch();
    if (!$hasFiscalStatus) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN fiscal_status ENUM('not_requested','pending','issued','failed') NOT NULL DEFAULT 'not_requested' AFTER fiscal_document_type");
    }
}
