CREATE DATABASE IF NOT EXISTS `ERP` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ERP`;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    category ENUM('pneu','roda') NOT NULL DEFAULT 'pneu',
    item_condition ENUM('novo','usado') NOT NULL DEFAULT 'novo',
    used_tire_condition ENUM('seminovo','meia_vida','abaixo_50_twi','seminovo_com_reparo') NULL,
    brand VARCHAR(80) NULL,
    model VARCHAR(40) NULL,
    width VARCHAR(10) NULL,
    profile VARCHAR(10) NULL,
    rim VARCHAR(10) NULL,
    location VARCHAR(120) NULL,
    image_path VARCHAR(255) NULL,
    cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cars_name (name)
);

CREATE TABLE IF NOT EXISTS product_cars (
    product_id INT NOT NULL,
    car_id INT NOT NULL,
    PRIMARY KEY (product_id, car_id),
    CONSTRAINT fk_product_cars_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_cars_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    movement_type ENUM('in','out') NOT NULL,
    quantity INT NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    tax_id VARCHAR(18) NOT NULL,
    car VARCHAR(120) NULL,
    notes TEXT NULL,
    address_street VARCHAR(160) NULL,
    address_number VARCHAR(20) NULL,
    address_district VARCHAR(100) NULL,
    address_city VARCHAR(100) NULL,
    address_state CHAR(2) NULL,
    address_zip VARCHAR(10) NULL,
    address_country VARCHAR(60) NULL DEFAULT 'Brasil',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_customers_tax_id (tax_id)
);

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NULL,
    customer_name VARCHAR(120) NULL,
    seller_name VARCHAR(40) NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    payment_status ENUM('paid','pending') NOT NULL DEFAULT 'paid',
    fiscal_document_type ENUM('none','nfe') NOT NULL DEFAULT 'none',
    fiscal_status ENUM('not_requested','pending','issued','failed') NOT NULL DEFAULT 'not_requested',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_item_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE IF NOT EXISTS accounts_payable (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(160) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS accounts_receivable (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(160) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    sale_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_receivable_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
);

CREATE TABLE IF NOT EXISTS costs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(160) NOT NULL,
    category VARCHAR(80) NULL,
    amount DECIMAL(10,2) NOT NULL,
    cost_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bank_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank ENUM('bb','santander','itau') NOT NULL,
    external_id VARCHAR(160) NULL,
    source ENUM('manual','ofx','csv') NOT NULL DEFAULT 'manual',
    transaction_type ENUM('in','out') NOT NULL,
    description VARCHAR(160) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_date DATE NOT NULL,
    reference VARCHAR(120) NULL,
    reconciled TINYINT(1) NOT NULL DEFAULT 0,
    raw_payload LONGTEXT NULL,
    sale_id INT NULL,
    cost_id INT NULL,
    accounts_receivable_id INT NULL,
    accounts_payable_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bank_external_source (bank, external_id, source)
);

CREATE TABLE IF NOT EXISTS fiscal_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    document_type ENUM('nfce','nfe') NOT NULL DEFAULT 'nfe',
    reference_code VARCHAR(64) NOT NULL,
    environment ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
    status VARCHAR(30) NOT NULL DEFAULT 'pendente',
    focus_id VARCHAR(120) NULL,
    access_key VARCHAR(64) NULL,
    number VARCHAR(30) NULL,
    series VARCHAR(20) NULL,
    danfe_path VARCHAR(255) NULL,
    xml_path VARCHAR(255) NULL,
    message TEXT NULL,
    request_payload LONGTEXT NULL,
    response_payload LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_fiscal_reference (reference_code),
    KEY idx_fiscal_sale (sale_id),
    CONSTRAINT fk_fiscal_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
);

