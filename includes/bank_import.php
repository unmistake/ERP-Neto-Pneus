<?php

declare(strict_types=1);

require_once __DIR__ . '/bank_schema.php';

function bankImportMoneyToFloat(string $value): float
{
    $value = trim($value);
    $value = str_replace(['R$', ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }
    return (float) $value;
}

function bankImportNormalizeDate(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m) === 1) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('/^(\d{2})(\d{2})(\d{4})/', $value, $m) === 1) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return date('Y-m-d', strtotime($value) ?: time());
}

function bankImportInsert(PDO $pdo, array $txn): bool
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO bank_transactions
            (bank, external_id, source, transaction_type, description, amount, transaction_date, reference, raw_payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $txn['bank'],
        $txn['external_id'],
        $txn['source'],
        $txn['transaction_type'],
        $txn['description'],
        $txn['amount'],
        $txn['transaction_date'],
        $txn['reference'],
        $txn['raw_payload'],
    ]);

    return $stmt->rowCount() > 0;
}

function bankImportCsv(PDO $pdo, string $bank, string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Nao foi possivel abrir o CSV.');
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        throw new RuntimeException('CSV vazio.');
    }
    rewind($handle);

    $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!is_array($headers)) {
        fclose($handle);
        throw new RuntimeException('Cabecalho CSV invalido.');
    }

    $normalizedHeaders = array_map(static fn ($h) => strtolower(trim((string) $h)), $headers);
    $inserted = 0;
    $skipped = 0;
    $line = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line++;
        $data = [];
        foreach ($normalizedHeaders as $i => $header) {
            $data[$header] = trim((string) ($row[$i] ?? ''));
        }

        $date = $data['data'] ?? $data['date'] ?? $data['dt'] ?? $data['lancamento'] ?? '';
        $description = $data['descricao'] ?? $data['descrição'] ?? $data['historico'] ?? $data['histórico'] ?? $data['description'] ?? '';
        $reference = $data['referencia'] ?? $data['referência'] ?? $data['documento'] ?? $data['doc'] ?? '';
        $amountText = $data['valor'] ?? $data['amount'] ?? '';
        $typeText = strtolower($data['tipo'] ?? $data['type'] ?? $data['natureza'] ?? '');

        if ($date === '' || $description === '' || $amountText === '') {
            $skipped++;
            continue;
        }

        $amountSigned = bankImportMoneyToFloat($amountText);
        $type = $amountSigned < 0 || str_contains($typeText, 'saida') || str_contains($typeText, 'd') || str_contains($typeText, 'deb') ? 'out' : 'in';
        $amount = abs($amountSigned);
        if ($amount <= 0) {
            $skipped++;
            continue;
        }

        $externalId = sha1($bank . '|csv|' . $line . '|' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $txn = [
            'bank' => $bank,
            'external_id' => $externalId,
            'source' => 'csv',
            'transaction_type' => $type,
            'description' => mb_substr($description, 0, 160),
            'amount' => $amount,
            'transaction_date' => bankImportNormalizeDate($date),
            'reference' => $reference !== '' ? mb_substr($reference, 0, 120) : null,
            'raw_payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (bankImportInsert($pdo, $txn)) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    fclose($handle);
    return ['received' => $inserted + $skipped, 'inserted' => $inserted, 'skipped' => $skipped];
}

function bankImportOfxTag(string $entry, string $tag): string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]+)/i', $entry, $m) === 1) {
        return trim($m[1]);
    }
    if (preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/is', $entry, $m) === 1) {
        return trim(strip_tags($m[1]));
    }
    return '';
}

function bankImportOfx(PDO $pdo, string $bank, string $path): array
{
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        throw new RuntimeException('OFX vazio ou invalido.');
    }

    preg_match_all('/<STMTTRN>(.*?)(?=<STMTTRN>|<\/BANKTRANLIST>)/is', $content, $matches);
    $entries = $matches[1] ?? [];

    $inserted = 0;
    $skipped = 0;
    foreach ($entries as $entry) {
        $amountSigned = bankImportMoneyToFloat(bankImportOfxTag($entry, 'TRNAMT'));
        $description = bankImportOfxTag($entry, 'MEMO') ?: bankImportOfxTag($entry, 'NAME') ?: 'Lancamento OFX';
        $date = bankImportNormalizeDate(bankImportOfxTag($entry, 'DTPOSTED'));
        $reference = bankImportOfxTag($entry, 'CHECKNUM') ?: bankImportOfxTag($entry, 'REFNUM');
        $externalId = bankImportOfxTag($entry, 'FITID');
        if ($externalId === '') {
            $externalId = sha1($bank . '|ofx|' . $entry);
        }

        $amount = abs($amountSigned);
        if ($amount <= 0) {
            $skipped++;
            continue;
        }

        $txn = [
            'bank' => $bank,
            'external_id' => $externalId,
            'source' => 'ofx',
            'transaction_type' => $amountSigned < 0 ? 'out' : 'in',
            'description' => mb_substr($description, 0, 160),
            'amount' => $amount,
            'transaction_date' => $date,
            'reference' => $reference !== '' ? mb_substr($reference, 0, 120) : null,
            'raw_payload' => $entry,
        ];

        if (bankImportInsert($pdo, $txn)) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    return ['received' => count($entries), 'inserted' => $inserted, 'skipped' => $skipped];
}

function bankImportStatement(PDO $pdo, string $bank, string $path, string $originalName): array
{
    ensureBankTransactionsSchema($pdo);

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'ofx') {
        return bankImportOfx($pdo, $bank, $path);
    }
    if ($extension === 'csv') {
        return bankImportCsv($pdo, $bank, $path);
    }

    throw new RuntimeException('Formato nao suportado. Envie CSV ou OFX.');
}

