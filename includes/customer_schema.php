<?php

declare(strict_types=1);

function ensureCustomerAddressSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            email VARCHAR(160) NULL,
            phone VARCHAR(20) NOT NULL,
            tax_id VARCHAR(18) NOT NULL,
            password_hash VARCHAR(255) NULL,
            car VARCHAR(120) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_customers_tax_id (tax_id)
        )"
    );

    $columns = [
        'email' => "ALTER TABLE customers ADD COLUMN email VARCHAR(160) NULL AFTER last_name",
        'password_hash' => "ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) NULL AFTER tax_id",
        'external_auth_id' => "ALTER TABLE customers ADD COLUMN external_auth_id CHAR(36) NULL AFTER password_hash",
        'address_street' => "ALTER TABLE customers ADD COLUMN address_street VARCHAR(160) NULL AFTER notes",
        'address_number' => "ALTER TABLE customers ADD COLUMN address_number VARCHAR(20) NULL AFTER address_street",
        'address_district' => "ALTER TABLE customers ADD COLUMN address_district VARCHAR(100) NULL AFTER address_number",
        'address_city' => "ALTER TABLE customers ADD COLUMN address_city VARCHAR(100) NULL AFTER address_district",
        'address_state' => "ALTER TABLE customers ADD COLUMN address_state CHAR(2) NULL AFTER address_city",
        'address_zip' => "ALTER TABLE customers ADD COLUMN address_zip VARCHAR(10) NULL AFTER address_state",
        'address_country' => "ALTER TABLE customers ADD COLUMN address_country VARCHAR(60) NULL DEFAULT 'Brasil' AFTER address_zip",
    ];

    foreach ($columns as $column => $sql) {
        $exists = (bool) $pdo->query("SHOW COLUMNS FROM customers LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }

    $hasExternalAuthIndex = (bool) $pdo->query("SHOW INDEX FROM customers WHERE Key_name = 'uk_customers_external_auth_id'")->fetch();
    if (!$hasExternalAuthIndex) {
        $pdo->exec('ALTER TABLE customers ADD UNIQUE KEY uk_customers_external_auth_id (external_auth_id)');
    }
}
