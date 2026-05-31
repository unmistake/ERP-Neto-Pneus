<?php

declare(strict_types=1);

function fiscalEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fiscal_documents (
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
        )"
    );

    $columns = [
        'danfe_path' => "ALTER TABLE fiscal_documents ADD COLUMN danfe_path VARCHAR(255) NULL AFTER series",
        'xml_path' => "ALTER TABLE fiscal_documents ADD COLUMN xml_path VARCHAR(255) NULL AFTER danfe_path",
    ];

    foreach ($columns as $column => $sql) {
        $exists = (bool) $pdo->query("SHOW COLUMNS FROM fiscal_documents LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }
}

function fiscalFocusConfig(): array
{
    $cfg = require __DIR__ . '/../config/fiscal.php';
    $focus = $cfg['focus'] ?? [];
    $environment = (string) ($focus['environment'] ?? 'homologacao');
    $token = trim((string) ($focus['token'] ?? ''));
    $baseUrl = trim((string) ($focus['base_url'] ?? ''));

    if ($baseUrl === '') {
        $baseUrl = $environment === 'producao'
            ? 'https://api.focusnfe.com.br'
            : 'https://homologacao.focusnfe.com.br';
    }

    return [
        'environment' => $environment === 'producao' ? 'producao' : 'homologacao',
        'token' => $token,
        'serie' => max(1, (int) ($focus['serie'] ?? 1)),
        'base_url' => rtrim($baseUrl, '/'),
        'issuer' => $focus['issuer'] ?? [],
        'nfe_defaults' => $focus['nfe_defaults'] ?? ($focus['nfce_defaults'] ?? []),
        'nfe_recipient_address' => $focus['nfe_recipient_address'] ?? [],
    ];
}

function fiscalFocusRequest(string $method, string $path, array $payload = []): array
{
    $cfg = fiscalFocusConfig();
    if ($cfg['token'] === '') {
        throw new RuntimeException('FOCUS_TOKEN nao configurado em config/fiscal.php (ou variavel de ambiente).');
    }

    $url = $cfg['base_url'] . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar requisicao HTTP para Focus.');
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, $cfg['token'] . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erro de comunicacao com Focus: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        $parsed = ['raw' => $raw];
    }

    return ['status' => $status, 'body' => $parsed, 'raw' => $raw];
}

function fiscalFocusDownload(string $path): array
{
    $cfg = fiscalFocusConfig();
    if ($cfg['token'] === '') {
        throw new RuntimeException('FOCUS_TOKEN nao configurado em config/fiscal.php (ou variavel de ambiente).');
    }

    $url = preg_match('/^https?:\/\//i', $path) === 1 ? $path : $cfg['base_url'] . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar download na Focus.');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERPWD, $cfg['token'] . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erro ao baixar PDF da Focus: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Focus retornou HTTP ' . $status . ' ao baixar o PDF.');
    }

    return [
        'content' => $body,
        'content_type' => $contentType !== '' ? $contentType : 'application/pdf',
    ];
}

function fiscalBuildNfePayload(PDO $pdo, int $saleId, string $referenceCode): array
{
    $cfg = fiscalFocusConfig();
    $issuer = $cfg['issuer'];
    $defaults = $cfg['nfe_defaults'];
    $recipientAddress = $cfg['nfe_recipient_address'];
    $issuerCnpj = fiscalOnlyDigits((string) ($issuer['cnpj'] ?? ''));
    $issuerIe = fiscalOnlyDigits((string) ($issuer['inscricao_estadual'] ?? ''));

    if ($issuerCnpj === '') {
        throw new RuntimeException('Informe o CNPJ do emitente em config/fiscal.php > focus > issuer > cnpj.');
    }
    if ($issuerIe === '') {
        throw new RuntimeException('Informe a Inscricao Estadual do emitente em config/fiscal.php > focus > issuer > inscricao_estadual.');
    }

    $saleStmt = $pdo->prepare(
        'SELECT s.id, s.customer_name, s.total_amount, s.payment_method, s.created_at,
                c.first_name, c.last_name, c.tax_id, c.phone,
                c.address_street, c.address_number, c.address_district, c.address_city,
                c.address_state, c.address_zip, c.address_country
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.id = ?'
    );
    $saleStmt->execute([$saleId]);
    $sale = $saleStmt->fetch();
    if (!$sale) {
        throw new RuntimeException('Venda nao encontrada.');
    }

    $itemsStmt = $pdo->prepare(
        'SELECT si.product_id, si.quantity, si.unit_price, p.name, p.sale_price
         FROM sale_items si
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.sale_id = ?
         ORDER BY si.id ASC'
    );
    $itemsStmt->execute([$saleId]);
    $items = $itemsStmt->fetchAll();
    if (!$items) {
        throw new RuntimeException('Venda sem itens para emissao fiscal.');
    }

    $nfeItems = [];
    $itemIndex = 1;
    foreach ($items as $it) {
        $qty = (float) $it['quantity'];
        $price = (float) $it['unit_price'];
        $total = round($qty * $price, 2);

        $nfeItems[] = [
            'numero_item' => (string) $itemIndex++,
            'codigo_produto' => 'P' . (int) $it['product_id'],
            'descricao' => mb_substr((string) ($it['name'] ?? 'Item'), 0, 120),
            'codigo_ncm' => (string) ($defaults['codigo_ncm'] ?? '40111000'),
            'cfop' => (string) ($defaults['cfop'] ?? '5102'),
            'unidade_comercial' => (string) ($defaults['unidade'] ?? 'UN'),
            'quantidade_comercial' => $qty,
            'valor_unitario_comercial' => $price,
            'valor_bruto' => $total,
            'unidade_tributavel' => (string) ($defaults['unidade'] ?? 'UN'),
            'quantidade_tributavel' => $qty,
            'valor_unitario_tributavel' => $price,
            'icms_origem' => (string) ($defaults['icms_origem'] ?? '0'),
            'icms_situacao_tributaria' => (string) ($defaults['icms_situacao_tributaria'] ?? '102'),
            'pis_situacao_tributaria' => (string) ($defaults['pis_situacao_tributaria'] ?? '49'),
            'cofins_situacao_tributaria' => (string) ($defaults['cofins_situacao_tributaria'] ?? '49'),
        ];
    }

    $emissionDate = date('c');
    $payload = [
        'cnpj_emitente' => $issuerCnpj,
        'inscricao_estadual_emitente' => $issuerIe,
        'regime_tributario_emitente' => (string) ($issuer['regime_tributario'] ?? '1'),

        'natureza_operacao' => (string) ($defaults['natureza_operacao'] ?? 'Venda de mercadoria'),
        'data_emissao' => $emissionDate,
        'tipo_documento' => 1,
        'finalidade_emissao' => 1,
        'consumidor_final' => 1,
        'local_destino' => (string) ($defaults['local_destino'] ?? '1'),
        'presenca_comprador' => (string) ($defaults['presenca_comprador'] ?? '1'),
        'indicador_inscricao_estadual_destinatario' => 9,
        'items' => $nfeItems,
        'valor_frete' => 0,
        'valor_seguro' => 0,
        'valor_desconto' => 0,
        'valor_outras_despesas' => 0,
        'modalidade_frete' => (string) ($defaults['modalidade_frete'] ?? '9'),
        'informacoes_adicionais_contribuinte' => 'Emitido via ERP - Ref ' . $referenceCode,
        'formas_pagamento' => [
            [
                'indicador_pagamento' => ((string) $sale['payment_method']) === 'prazo' ? '1' : '0',
                'forma_pagamento' => fiscalMapPaymentMethod((string) $sale['payment_method']),
                'valor_pagamento' => (float) $sale['total_amount'],
                'tipo_integracao' => 2,
            ],
        ],
        'serie' => (string) $cfg['serie'],
    ];

    $accountingCnpj = fiscalOnlyDigits((string) ($issuer['cpf_cnpj_contabilidade'] ?? ''));
    if ($accountingCnpj !== '') {
        $payload['cpf_cnpj_contabilidade'] = $accountingCnpj;
    }

    $taxId = fiscalOnlyDigits((string) ($sale['tax_id'] ?? ''));
    if (strlen($taxId) !== 11 && strlen($taxId) !== 14) {
        throw new RuntimeException('NF-e exige CPF ou CNPJ valido do cliente.');
    }

    $address = [
        'logradouro' => trim((string) ($sale['address_street'] ?? '')),
        'numero' => trim((string) ($sale['address_number'] ?? '')),
        'bairro' => trim((string) ($sale['address_district'] ?? '')),
        'municipio' => trim((string) ($sale['address_city'] ?? '')),
        'uf' => strtoupper(trim((string) ($sale['address_state'] ?? ''))),
        'cep' => trim((string) ($sale['address_zip'] ?? '')),
        'pais' => trim((string) ($sale['address_country'] ?? 'Brasil')),
    ];

    foreach ($address as $field => $value) {
        if ($value === '' && isset($recipientAddress[$field])) {
            $address[$field] = trim((string) $recipientAddress[$field]);
        }
    }

    $missingAddress = [];
    foreach (['logradouro', 'numero', 'bairro', 'municipio', 'uf', 'cep'] as $field) {
        if ($address[$field] === '') {
            $missingAddress[] = $field;
        }
    }
    if (count($missingAddress) > 0) {
        throw new RuntimeException('NF-e exige endereco do destinatario. Preencha no CRM/PDV: ' . implode(', ', $missingAddress) . '.');
    }

    $payload['nome_destinatario'] = mb_substr((string) ($sale['customer_name'] ?: trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? ''))), 0, 60);
    if (strlen($taxId) === 11) {
        $payload['cpf_destinatario'] = $taxId;
    } else {
        $payload['cnpj_destinatario'] = $taxId;
    }
    $payload['logradouro_destinatario'] = $address['logradouro'];
    $payload['numero_destinatario'] = $address['numero'];
    $payload['bairro_destinatario'] = $address['bairro'];
    $payload['municipio_destinatario'] = $address['municipio'];
    $payload['uf_destinatario'] = strtoupper($address['uf']);
    $payload['cep_destinatario'] = fiscalOnlyDigits($address['cep']);
    $payload['pais_destinatario'] = $address['pais'] !== '' ? $address['pais'] : 'Brasil';

    $phone = fiscalOnlyDigits((string) ($sale['phone'] ?? ''));
    if ($phone !== '') {
        $payload['telefone_destinatario'] = $phone;
    }

    return $payload;
}

function fiscalNfePreview(PDO $pdo, int $saleId): array
{
    $cfg = fiscalFocusConfig();
    $referenceCode = 'PREVIEWNFE' . $saleId . 'T' . date('YmdHis');
    $payload = fiscalBuildNfePayload($pdo, $saleId, $referenceCode);

    return [
        'environment' => $cfg['environment'],
        'base_url' => $cfg['base_url'],
        'issuer_cnpj' => $payload['cnpj_emitente'] ?? '',
        'issuer_ie' => $payload['inscricao_estadual_emitente'] ?? '',
        'token_configured' => $cfg['token'] !== '',
        'token_preview' => $cfg['token'] !== '' ? substr($cfg['token'], 0, 4) . '...' . substr($cfg['token'], -4) : '',
        'payload' => $payload,
    ];
}

function fiscalOnlyDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function fiscalExtractValueRecursive(array $data, array $keys): ?string
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && !is_array($data[$key])) {
            return (string) $data[$key];
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $found = fiscalExtractValueRecursive($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function fiscalExtractXmlTag(string $xml, string $tag): ?string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/s', $xml, $matches) === 1) {
        return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    return null;
}

function fiscalExtractDanfePath(array $body): ?string
{
    return fiscalExtractValueRecursive($body, ['caminho_danfe', 'url_danfe', 'url_danfse']);
}

function fiscalExtractXmlPath(array $body): ?string
{
    return fiscalExtractValueRecursive($body, ['caminho_xml_nota_fiscal', 'caminho_xml', 'url_xml']);
}

function fiscalClassifyFocusResponse(int $httpStatus, array $body, string $raw): array
{
    $cStat = fiscalExtractValueRecursive($body, ['cStat', 'codigo_status', 'codigo_sefaz']);
    if ($cStat === null && trim($raw) !== '') {
        $cStat = fiscalExtractXmlTag($raw, 'cStat');
    }

    $reason = fiscalExtractValueRecursive($body, ['xMotivo', 'motivo', 'mensagem', 'message', 'erro', 'error', 'erro_autorizacao']);
    if ($reason === null && trim($raw) !== '') {
        $reason = fiscalExtractXmlTag($raw, 'xMotivo');
    }

    $focusStatus = strtolower((string) (fiscalExtractValueRecursive($body, ['status']) ?? ''));

    if (in_array($focusStatus, ['erro_autorizacao', 'erro_autorizacao_nfe', 'rejeitado', 'rejeitada', 'cancelado', 'cancelada'], true)) {
        return [
            'document_status' => 'rejeitado',
            'sale_status' => 'failed',
            'message' => $reason ?: $focusStatus,
        ];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return [
            'document_status' => 'erro_envio',
            'sale_status' => 'failed',
            'message' => $reason ?: ('Erro HTTP ' . $httpStatus . ' na Focus.'),
        ];
    }

    if ($cStat !== null && $cStat !== '100') {
        return [
            'document_status' => 'rejeitado',
            'sale_status' => 'failed',
            'message' => $reason ?: ('Rejeicao SEFAZ cStat ' . $cStat . '.'),
        ];
    }

    if ($cStat === '100' || in_array($focusStatus, ['autorizado', 'autorizada'], true)) {
        return [
            'document_status' => 'autorizado',
            'sale_status' => 'issued',
            'message' => $reason ?: 'NF-e autorizada.',
        ];
    }

    return [
        'document_status' => 'processando',
        'sale_status' => 'pending',
        'message' => $reason ?: ($focusStatus !== '' ? $focusStatus : 'Solicitacao enviada.'),
    ];
}

function fiscalMapPaymentMethod(string $method): string
{
    $map = [
        'dinheiro' => '01',
        'cartao' => '03',
        'pix' => '17',
        'prazo' => '15',
    ];
    return $map[$method] ?? '99';
}

function fiscalIssueNfeBySale(PDO $pdo, int $saleId): array
{
    fiscalEnsureSchema($pdo);

    $cfg = fiscalFocusConfig();
    $referenceCode = 'NFE' . $saleId . 'T' . date('YmdHis');
    $payload = fiscalBuildNfePayload($pdo, $saleId, $referenceCode);

    $response = fiscalFocusRequest('POST', '/v2/nfe?ref=' . urlencode($referenceCode), $payload);
    $status = $response['status'];
    $body = $response['body'];
    $raw = (string) ($response['raw'] ?? '');

    $classification = fiscalClassifyFocusResponse($status, $body, $raw);
    $docStatus = $classification['document_status'];
    $message = $classification['message'];
    $focusId = (string) ($body['id'] ?? $body['chave'] ?? '');
    $accessKey = (string) ($body['chave'] ?? '');
    $number = isset($body['numero']) ? (string) $body['numero'] : null;
    $series = isset($body['serie']) ? (string) $body['serie'] : null;
    $danfePath = fiscalExtractDanfePath($body);
    $xmlPath = fiscalExtractXmlPath($body);

    $stmt = $pdo->prepare(
        'INSERT INTO fiscal_documents
            (sale_id, document_type, reference_code, environment, status, focus_id, access_key, number, series, danfe_path, xml_path, message, request_payload, response_payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $saleId,
        'nfe',
        $referenceCode,
        $cfg['environment'],
        $docStatus,
        $focusId !== '' ? $focusId : null,
        $accessKey !== '' ? $accessKey : null,
        $number,
        $series,
        $danfePath,
        $xmlPath,
        $message,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return [
        'fiscal_document_id' => (int) $pdo->lastInsertId(),
        'reference_code' => $referenceCode,
        'focus_http_status' => $status,
        'focus_response' => $body,
        'message' => $message,
        'sale_fiscal_status' => $classification['sale_status'],
        'status' => $docStatus,
    ];
}

function fiscalSyncLatestNfeBySale(PDO $pdo, int $saleId): array
{
    fiscalEnsureSchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, reference_code
         FROM fiscal_documents
         WHERE sale_id = ? AND document_type = 'nfe'
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$saleId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw new RuntimeException('Nenhuma NF-e encontrada para sincronizar nesta venda.');
    }

    $referenceCode = (string) $doc['reference_code'];
    $response = fiscalFocusRequest('GET', '/v2/nfe/' . urlencode($referenceCode));
    $status = $response['status'];
    $body = $response['body'];
    $raw = (string) ($response['raw'] ?? '');
    $classification = fiscalClassifyFocusResponse($status, $body, $raw);

    $focusId = (string) ($body['id'] ?? $body['chave'] ?? '');
    $accessKey = (string) ($body['chave'] ?? fiscalExtractValueRecursive($body, ['chNFe']) ?? '');
    $number = isset($body['numero']) ? (string) $body['numero'] : null;
    $series = isset($body['serie']) ? (string) $body['serie'] : null;
    $danfePath = fiscalExtractDanfePath($body);
    $xmlPath = fiscalExtractXmlPath($body);

    $updDoc = $pdo->prepare(
        'UPDATE fiscal_documents
         SET status = ?, focus_id = COALESCE(?, focus_id), access_key = COALESCE(?, access_key),
             number = COALESCE(?, number), series = COALESCE(?, series),
             danfe_path = COALESCE(?, danfe_path), xml_path = COALESCE(?, xml_path),
             message = ?, response_payload = ?
         WHERE id = ?'
    );
    $updDoc->execute([
        $classification['document_status'],
        $focusId !== '' ? $focusId : null,
        $accessKey !== '' ? $accessKey : null,
        $number,
        $series,
        $danfePath,
        $xmlPath,
        $classification['message'],
        json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        (int) $doc['id'],
    ]);

    $updSale = $pdo->prepare("UPDATE sales SET fiscal_document_type = 'nfe', fiscal_status = ? WHERE id = ?");
    $updSale->execute([$classification['sale_status'], $saleId]);

    return [
        'fiscal_document_id' => (int) $doc['id'],
        'reference_code' => $referenceCode,
        'focus_http_status' => $status,
        'focus_response' => $body,
        'message' => $classification['message'],
        'sale_fiscal_status' => $classification['sale_status'],
        'status' => $classification['document_status'],
    ];
}

function fiscalDownloadLatestNfeDanfe(PDO $pdo, int $saleId): array
{
    fiscalEnsureSchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, reference_code, danfe_path, response_payload
         FROM fiscal_documents
         WHERE sale_id = ? AND document_type = 'nfe'
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$saleId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw new RuntimeException('Nenhuma NF-e encontrada para download nesta venda.');
    }

    $danfePath = trim((string) ($doc['danfe_path'] ?? ''));
    if ($danfePath === '') {
        $result = fiscalSyncLatestNfeBySale($pdo, $saleId);
        $danfePath = (string) fiscalExtractDanfePath((array) ($result['focus_response'] ?? []));
    }

    if ($danfePath === '') {
        throw new RuntimeException('DANFE ainda nao disponivel para esta NF-e. Sincronize novamente em instantes.');
    }

    $download = fiscalFocusDownload($danfePath);

    return [
        'filename' => 'nfe-venda-' . $saleId . '.pdf',
        'content_type' => $download['content_type'],
        'content' => $download['content'],
    ];
}
