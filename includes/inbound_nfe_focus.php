<?php

declare(strict_types=1);

require_once __DIR__ . '/fiscal_focus.php';

function inboundNfeEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inbound_nfe_sync_state (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient_cnpj VARCHAR(14) NOT NULL,
            last_version BIGINT NOT NULL DEFAULT 0,
            last_total_count INT NULL,
            last_synced_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_inbound_nfe_sync_cnpj (recipient_cnpj)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inbound_nfes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            access_key VARCHAR(44) NOT NULL,
            recipient_cnpj VARCHAR(14) NOT NULL,
            version BIGINT NULL,
            status VARCHAR(60) NULL,
            manifest_status VARCHAR(80) NULL,
            supplier_name VARCHAR(180) NULL,
            supplier_cnpj VARCHAR(14) NULL,
            number VARCHAR(30) NULL,
            series VARCHAR(20) NULL,
            issue_date DATETIME NULL,
            total_amount DECIMAL(12,2) NULL,
            danfe_path VARCHAR(255) NULL,
            xml_path VARCHAR(255) NULL,
            raw_payload LONGTEXT NULL,
            last_synced_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_inbound_nfe_access_key (access_key),
            KEY idx_inbound_nfe_recipient (recipient_cnpj),
            KEY idx_inbound_nfe_supplier (supplier_cnpj),
            KEY idx_inbound_nfe_issue_date (issue_date)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inbound_nfe_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inbound_nfe_id INT NOT NULL,
            item_number INT NULL,
            supplier_sku VARCHAR(80) NULL,
            description VARCHAR(255) NOT NULL,
            ncm VARCHAR(20) NULL,
            cfop VARCHAR(10) NULL,
            unit VARCHAR(20) NULL,
            quantity DECIMAL(12,4) NULL,
            unit_price DECIMAL(12,4) NULL,
            total_amount DECIMAL(12,2) NULL,
            raw_payload LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inbound_nfe_item_doc (inbound_nfe_id),
            CONSTRAINT fk_inbound_nfe_item_doc FOREIGN KEY (inbound_nfe_id) REFERENCES inbound_nfes(id) ON DELETE CASCADE
        )"
    );
}

function inboundNfeConfig(): array
{
    $cfg = fiscalFocusConfig();
    $issuerCnpj = inboundNfeOnlyDigits((string) (($cfg['issuer']['cnpj'] ?? '') ?: ''));

    return [
        'base_url' => $cfg['base_url'],
        'token' => $cfg['token'],
        'recipient_cnpj' => $issuerCnpj,
    ];
}

function inboundNfeOnlyDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function inboundNfeFormatDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function inboundNfeNumber($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    $normalized = str_replace(['.', ','], ['', '.'], (string) $value);
    return is_numeric($normalized) ? (float) $normalized : null;
}

function inboundNfeFindValue(array $data, array $paths)
{
    foreach ($paths as $path) {
        $current = $data;
        $found = true;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $found = false;
                break;
            }
            $current = $current[$part];
        }
        if ($found && $current !== null && $current !== '') {
            return $current;
        }
    }

    foreach ($data as $key => $value) {
        if (in_array((string) $key, $paths, true) && $value !== null && $value !== '') {
            return $value;
        }
        if (is_array($value)) {
            $found = inboundNfeFindValue($value, $paths);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

function inboundNfeFocusJsonRequest(string $method, string $path, array $query = [], array $payload = []): array
{
    $cfg = inboundNfeConfig();
    if ($cfg['token'] === '') {
        throw new RuntimeException('FOCUS_TOKEN nao configurado em config/fiscal.php (ou variavel de ambiente).');
    }

    $url = $cfg['base_url'] . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [];
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar requisicao HTTP para Focus.');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, $cfg['token'] . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
        $length = strlen($line);
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    });

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $payload) {
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

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = ['raw' => $raw];
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'raw' => $raw];
}

function inboundNfeFocusDownloadPdf(string $accessKey): array
{
    $cfg = inboundNfeConfig();
    if ($cfg['token'] === '') {
        throw new RuntimeException('FOCUS_TOKEN nao configurado em config/fiscal.php (ou variavel de ambiente).');
    }

    $url = $cfg['base_url'] . '/v2/nfes_recebidas/' . urlencode($accessKey) . '.pdf';
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar download do DANFE.');
    }

    $headers = [];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_USERPWD, $cfg['token'] . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
        $length = strlen($line);
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    });

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erro ao baixar DANFE da Focus: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($status === 302 && !empty($headers['location'])) {
        $redirect = curl_init($headers['location']);
        if ($redirect === false) {
            throw new RuntimeException('Falha ao seguir redirecionamento do DANFE.');
        }
        curl_setopt($redirect, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($redirect, CURLOPT_TIMEOUT, 60);
        $body = curl_exec($redirect);
        if ($body === false) {
            $err = curl_error($redirect);
            curl_close($redirect);
            throw new RuntimeException('Erro ao baixar DANFE redirecionado: ' . $err);
        }
        $status = (int) curl_getinfo($redirect, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($redirect, CURLINFO_CONTENT_TYPE);
        curl_close($redirect);
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Focus retornou HTTP ' . $status . ' ao baixar o DANFE da NF-e recebida.');
    }

    return [
        'content' => $body,
        'content_type' => $contentType !== '' ? $contentType : 'application/pdf',
    ];
}

function inboundNfeNormalize(array $note, string $recipientCnpj): array
{
    $accessKey = inboundNfeOnlyDigits((string) (inboundNfeFindValue($note, ['chave_nfe', 'chave', 'chave_acesso', 'access_key']) ?? ''));
    $supplierCnpj = inboundNfeOnlyDigits((string) (inboundNfeFindValue($note, ['cnpj_emitente', 'emitente.cnpj', 'emitente.cpf_cnpj', 'fornecedor.cnpj']) ?? ''));

    return [
        'access_key' => $accessKey,
        'recipient_cnpj' => $recipientCnpj,
        'version' => (int) (inboundNfeFindValue($note, ['versao']) ?? 0),
        'status' => (string) (inboundNfeFindValue($note, ['status', 'situacao', 'status_nfe']) ?? ''),
        'manifest_status' => (string) (inboundNfeFindValue($note, ['manifesto', 'manifestacao', 'status_manifestacao', 'manifestacao_destinatario']) ?? ''),
        'supplier_name' => (string) (inboundNfeFindValue($note, ['nome_emitente', 'razao_social_emitente', 'emitente.nome', 'emitente.razao_social', 'fornecedor.nome']) ?? ''),
        'supplier_cnpj' => $supplierCnpj,
        'number' => (string) (inboundNfeFindValue($note, ['numero', 'numero_nfe', 'nNF']) ?? ''),
        'series' => (string) (inboundNfeFindValue($note, ['serie', 'series', 'serie_nfe']) ?? ''),
        'issue_date' => inboundNfeFormatDate((string) (inboundNfeFindValue($note, ['data_emissao', 'emissao', 'dhEmi', 'data']) ?? '')),
        'total_amount' => inboundNfeNumber(inboundNfeFindValue($note, ['valor_total', 'valor_nfe', 'total', 'vNF'])),
        'danfe_path' => (string) (inboundNfeFindValue($note, ['caminho_danfe', 'url_danfe']) ?? ''),
        'xml_path' => (string) (inboundNfeFindValue($note, ['caminho_xml', 'caminho_xml_nota_fiscal', 'url_xml']) ?? ''),
        'raw_payload' => json_encode($note, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function inboundNfeExtractItems(array $note): array
{
    $items = inboundNfeFindValue($note, ['itens', 'items', 'produtos']);
    return is_array($items) ? $items : [];
}

function inboundNfeUpsert(PDO $pdo, array $note, string $recipientCnpj): array
{
    $normalized = inboundNfeNormalize($note, $recipientCnpj);
    if (strlen($normalized['access_key']) !== 44) {
        return ['stored' => false, 'reason' => 'missing_access_key'];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO inbound_nfes
            (access_key, recipient_cnpj, version, status, manifest_status, supplier_name, supplier_cnpj, number, series, issue_date, total_amount, danfe_path, xml_path, raw_payload, last_synced_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            recipient_cnpj = VALUES(recipient_cnpj),
            version = GREATEST(COALESCE(version, 0), VALUES(version)),
            status = VALUES(status),
            manifest_status = VALUES(manifest_status),
            supplier_name = VALUES(supplier_name),
            supplier_cnpj = VALUES(supplier_cnpj),
            number = VALUES(number),
            series = VALUES(series),
            issue_date = VALUES(issue_date),
            total_amount = VALUES(total_amount),
            danfe_path = VALUES(danfe_path),
            xml_path = VALUES(xml_path),
            raw_payload = VALUES(raw_payload),
            last_synced_at = NOW()"
    );
    $stmt->execute([
        $normalized['access_key'],
        $normalized['recipient_cnpj'],
        $normalized['version'] ?: null,
        $normalized['status'] ?: null,
        $normalized['manifest_status'] ?: null,
        $normalized['supplier_name'] ?: null,
        $normalized['supplier_cnpj'] ?: null,
        $normalized['number'] ?: null,
        $normalized['series'] ?: null,
        $normalized['issue_date'],
        $normalized['total_amount'],
        $normalized['danfe_path'] ?: null,
        $normalized['xml_path'] ?: null,
        $normalized['raw_payload'],
    ]);

    $idStmt = $pdo->prepare('SELECT id FROM inbound_nfes WHERE access_key = ?');
    $idStmt->execute([$normalized['access_key']]);
    $inboundNfeId = (int) $idStmt->fetchColumn();

    $items = inboundNfeExtractItems($note);
    if ($items) {
        $pdo->prepare('DELETE FROM inbound_nfe_items WHERE inbound_nfe_id = ?')->execute([$inboundNfeId]);
        $itemStmt = $pdo->prepare(
            "INSERT INTO inbound_nfe_items
                (inbound_nfe_id, item_number, supplier_sku, description, ncm, cfop, unit, quantity, unit_price, total_amount, raw_payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $description = (string) (inboundNfeFindValue($item, ['descricao', 'xProd', 'produto.descricao', 'nome']) ?? 'Item sem descricao');
            $itemStmt->execute([
                $inboundNfeId,
                (int) (inboundNfeFindValue($item, ['numero_item', 'nItem']) ?? ($idx + 1)),
                (string) (inboundNfeFindValue($item, ['codigo_produto', 'cProd', 'sku']) ?? '') ?: null,
                mb_substr($description, 0, 255),
                (string) (inboundNfeFindValue($item, ['codigo_ncm', 'ncm', 'NCM']) ?? '') ?: null,
                (string) (inboundNfeFindValue($item, ['cfop', 'CFOP']) ?? '') ?: null,
                (string) (inboundNfeFindValue($item, ['unidade_comercial', 'uCom', 'unidade']) ?? '') ?: null,
                inboundNfeNumber(inboundNfeFindValue($item, ['quantidade_comercial', 'qCom', 'quantidade'])),
                inboundNfeNumber(inboundNfeFindValue($item, ['valor_unitario_comercial', 'vUnCom', 'valor_unitario'])),
                inboundNfeNumber(inboundNfeFindValue($item, ['valor_bruto', 'vProd', 'valor_total'])),
                json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    return ['stored' => true, 'id' => $inboundNfeId, 'version' => $normalized['version']];
}

function inboundNfeSync(PDO $pdo, bool $full = false, bool $pendingOnly = false): array
{
    inboundNfeEnsureSchema($pdo);
    $cfg = inboundNfeConfig();
    if ($cfg['recipient_cnpj'] === '') {
        throw new RuntimeException('CNPJ do emitente nao configurado para consultar NF-e recebidas.');
    }

    $stateStmt = $pdo->prepare('SELECT last_version FROM inbound_nfe_sync_state WHERE recipient_cnpj = ?');
    $stateStmt->execute([$cfg['recipient_cnpj']]);
    $lastVersion = (int) ($stateStmt->fetchColumn() ?: 0);

    $query = ['cnpj' => $cfg['recipient_cnpj']];
    if (!$full && $lastVersion > 0) {
        $query['versao'] = $lastVersion;
    }
    if ($pendingOnly) {
        $query['pendente'] = 1;
    }

    $response = inboundNfeFocusJsonRequest('GET', '/v2/nfes_recebidas', $query);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = (string) (inboundNfeFindValue($response['body'], ['mensagem', 'message', 'erro']) ?? $response['raw']);
        throw new RuntimeException('Focus retornou HTTP ' . $response['status'] . ' ao consultar NF-e recebidas: ' . $message);
    }

    $body = $response['body'];
    $notes = array_is_list($body)
        ? $body
        : (
            is_array($body['notas'] ?? null)
                ? $body['notas']
                : (
                    is_array($body['nfes'] ?? null)
                        ? $body['nfes']
                        : (
                            is_array($body['notas_fiscais'] ?? null)
                                ? $body['notas_fiscais']
                                : (is_array($body['nfe'] ?? null) ? $body['nfe'] : [])
                        )
                )
        );
    $stored = 0;
    $skipped = 0;
    $maxVersion = (int) ($response['headers']['x-max-version'] ?? $lastVersion);

    foreach ($notes as $note) {
        if (!is_array($note)) {
            $skipped++;
            continue;
        }
        $result = inboundNfeUpsert($pdo, $note, $cfg['recipient_cnpj']);
        if ($result['stored'] ?? false) {
            $stored++;
            $maxVersion = max($maxVersion, (int) ($result['version'] ?? 0));
        } else {
            $skipped++;
        }
    }

    $totalCount = isset($response['headers']['x-total-count']) ? (int) $response['headers']['x-total-count'] : count($notes);
    $saveState = $pdo->prepare(
        "INSERT INTO inbound_nfe_sync_state (recipient_cnpj, last_version, last_total_count, last_synced_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE last_version = GREATEST(last_version, VALUES(last_version)), last_total_count = VALUES(last_total_count), last_synced_at = NOW()"
    );
    $saveState->execute([$cfg['recipient_cnpj'], $maxVersion, $totalCount]);

    return [
        'stored' => $stored,
        'skipped' => $skipped,
        'total_count' => $totalCount,
        'max_version' => $maxVersion,
        'last_version_before' => $lastVersion,
    ];
}
