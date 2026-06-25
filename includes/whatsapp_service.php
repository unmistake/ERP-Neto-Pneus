<?php

declare(strict_types=1);

require_once __DIR__ . '/fiscal_focus.php';

function whatsappConfig(): array
{
    $cfg = require __DIR__ . '/../config/whatsapp.php';
    return $cfg['cloud_api'] ?? [];
}

function whatsappEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            fiscal_document_id INT NULL,
            customer_id INT NULL,
            recipient_phone VARCHAR(20) NOT NULL,
            message_type VARCHAR(40) NOT NULL DEFAULT 'nfe_pdf',
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            provider_message_id VARCHAR(120) NULL,
            error_message TEXT NULL,
            attempts INT NOT NULL DEFAULT 0,
            request_payload LONGTEXT NULL,
            response_payload LONGTEXT NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_whatsapp_fiscal_message (fiscal_document_id, message_type),
            KEY idx_whatsapp_sale (sale_id),
            KEY idx_whatsapp_status (status)
        )"
    );
}

function whatsappOnlyDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function whatsappNormalizeBrazilPhone(string $phone): string
{
    $digits = whatsappOnlyDigits($phone);
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        $digits = '55' . $digits;
    }

    if (!preg_match('/^55\d{10,11}$/', $digits)) {
        throw new RuntimeException('Telefone do cliente invalido para WhatsApp. Use DDD + numero.');
    }

    return $digits;
}

function whatsappCloudRequest(string $method, string $path, array $payload = [], ?array $file = null): array
{
    $cfg = whatsappConfig();
    $token = trim((string) ($cfg['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('WHATSAPP_ACCESS_TOKEN nao configurado.');
    }

    $version = trim((string) ($cfg['graph_version'] ?? 'v24.0'));
    $url = 'https://graph.facebook.com/' . $version . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar requisicao para WhatsApp.');
    }

    $headers = ['Authorization: Bearer ' . $token];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    if ($file !== null) {
        $postFields = $payload;
        $postFields['file'] = new CURLFile($file['path'], $file['type'], $file['name']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } else {
        $headers[] = 'Content-Type: application/json';
        if (count($payload) > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erro de comunicacao com WhatsApp: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = ['raw' => $raw];
    }

    if ($status < 200 || $status >= 300) {
        $message = (string) ($body['error']['message'] ?? $raw);
        throw new RuntimeException('WhatsApp HTTP ' . $status . ': ' . $message);
    }

    return ['status' => $status, 'body' => $body, 'raw' => $raw];
}

function whatsappSendNfePdf(PDO $pdo, int $saleId): array
{
    whatsappEnsureSchema($pdo);
    $cfg = whatsappConfig();
    if (empty($cfg['enabled'])) {
        return ['status' => 'skipped', 'message' => 'WhatsApp desativado.'];
    }

    $phoneNumberId = trim((string) ($cfg['phone_number_id'] ?? ''));
    if ($phoneNumberId === '') {
        throw new RuntimeException('WHATSAPP_PHONE_NUMBER_ID nao configurado.');
    }

    $stmt = $pdo->prepare(
        "SELECT fd.id AS fiscal_document_id, fd.number, s.customer_id, s.customer_name, c.phone
         FROM fiscal_documents fd
         INNER JOIN sales s ON s.id = fd.sale_id
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE fd.sale_id = ? AND fd.document_type = 'nfe' AND fd.status = 'autorizado'
         ORDER BY fd.id DESC
         LIMIT 1"
    );
    $stmt->execute([$saleId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Nenhuma NF-e autorizada encontrada para envio por WhatsApp.');
    }

    $fiscalDocumentId = (int) $row['fiscal_document_id'];
    $alreadySent = $pdo->prepare("SELECT id, status FROM whatsapp_messages WHERE fiscal_document_id = ? AND message_type = 'nfe_pdf' AND status = 'sent' LIMIT 1");
    $alreadySent->execute([$fiscalDocumentId]);
    if ($alreadySent->fetch()) {
        return ['status' => 'skipped', 'message' => 'NF-e ja enviada por WhatsApp.'];
    }

    $recipient = whatsappNormalizeBrazilPhone((string) ($row['phone'] ?? ''));
    $customerName = trim((string) ($row['customer_name'] ?? 'cliente'));
    $nfeNumber = trim((string) ($row['number'] ?? ''));
    $pdf = fiscalDownloadNfeDanfeByDocument($pdo, $saleId, $fiscalDocumentId);
    $filename = 'nfe-venda-' . $saleId . '.pdf';
    $tmpPath = tempnam(sys_get_temp_dir(), 'nfe-whatsapp-');
    if ($tmpPath === false) {
        throw new RuntimeException('Falha ao criar arquivo temporario da NF-e.');
    }

    $logStmt = $pdo->prepare(
        "INSERT INTO whatsapp_messages
            (sale_id, fiscal_document_id, customer_id, recipient_phone, message_type, status, attempts)
         VALUES (?, ?, ?, ?, 'nfe_pdf', 'pending', 1)
         ON DUPLICATE KEY UPDATE status = 'pending', attempts = attempts + 1, error_message = NULL"
    );
    $logStmt->execute([$saleId, $fiscalDocumentId, $row['customer_id'] ?: null, $recipient]);

    try {
        file_put_contents($tmpPath, (string) $pdf['content']);
        $upload = whatsappCloudRequest(
            'POST',
            '/' . rawurlencode($phoneNumberId) . '/media',
            ['messaging_product' => 'whatsapp', 'type' => 'application/pdf'],
            ['path' => $tmpPath, 'type' => 'application/pdf', 'name' => $filename]
        );
        $mediaId = (string) ($upload['body']['id'] ?? '');
        if ($mediaId === '') {
            throw new RuntimeException('WhatsApp nao retornou media_id para o PDF.');
        }

        $templateName = trim((string) ($cfg['template_name'] ?? ''));
        if ($templateName !== '') {
            $messagePayload = [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => (string) ($cfg['template_language'] ?? 'pt_BR')],
                    'components' => [
                        [
                            'type' => 'header',
                            'parameters' => [
                                ['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename]],
                            ],
                        ],
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $customerName],
                                ['type' => 'text', 'text' => $nfeNumber !== '' ? $nfeNumber : (string) $saleId],
                            ],
                        ],
                    ],
                ],
            ];
        } else {
            $messagePayload = [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'document',
                'document' => [
                    'id' => $mediaId,
                    'filename' => $filename,
                    'caption' => 'Olá, ' . $customerName . '. Sua NF-e foi emitida. Segue o PDF em anexo. Obrigado por comprar na Neto Rodas.',
                ],
            ];
        }

        $send = whatsappCloudRequest('POST', '/' . rawurlencode($phoneNumberId) . '/messages', $messagePayload);
        $messageId = (string) ($send['body']['messages'][0]['id'] ?? '');

        $upd = $pdo->prepare(
            "UPDATE whatsapp_messages
             SET status = 'sent', provider_message_id = ?, request_payload = ?, response_payload = ?, sent_at = NOW()
             WHERE fiscal_document_id = ? AND message_type = 'nfe_pdf'"
        );
        $upd->execute([
            $messageId !== '' ? $messageId : null,
            json_encode($messagePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($send['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $fiscalDocumentId,
        ]);

        return ['status' => 'sent', 'message_id' => $messageId];
    } catch (Throwable $e) {
        $upd = $pdo->prepare(
            "UPDATE whatsapp_messages
             SET status = 'failed', error_message = ?
             WHERE fiscal_document_id = ? AND message_type = 'nfe_pdf'"
        );
        $upd->execute([$e->getMessage(), $fiscalDocumentId]);
        throw $e;
    } finally {
        if (is_file($tmpPath)) {
            unlink($tmpPath);
        }
    }
}

function whatsappTrySendNfePdf(PDO $pdo, int $saleId): array
{
    try {
        return whatsappSendNfePdf($pdo, $saleId);
    } catch (Throwable $e) {
        return ['status' => 'failed', 'message' => $e->getMessage()];
    }
}
