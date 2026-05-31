<?php

declare(strict_types=1);

function bankStatementNormalizeHeader(string $header): string
{
    $header = trim(mb_strtolower($header, 'UTF-8'));
    $header = str_replace(
        ['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'],
        ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'],
        $header
    );
    return preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
}

function bankStatementFirstValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }

    return '';
}

function bankStatementParseAmount(string $value): float
{
    $value = trim(str_replace(["\xc2\xa0", 'R$', ' '], '', $value));
    $value = preg_replace('/[^\d,\.\-]/', '', $value) ?? '';

    if ($value === '' || $value === '-' || $value === ',') {
        return 0.0;
    }

    $hasComma = str_contains($value, ',');
    $hasDot = str_contains($value, '.');

    if ($hasComma && $hasDot) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif ($hasComma) {
        $value = str_replace(',', '.', $value);
    }

    return (float) $value;
}

function bankStatementParseDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+.*/', '', $value) ?? $value;
    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'dmY', 'Ymd'];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function bankStatementResolveType(string $rawType, float $signedAmount): string
{
    $type = bankStatementNormalizeHeader($rawType);
    if (in_array($type, ['d', 'debito', 'saida', 's', 'out', 'debit'], true)) {
        return 'out';
    }
    if (in_array($type, ['c', 'credito', 'entrada', 'e', 'in', 'credit'], true)) {
        return 'in';
    }

    return $signedAmount < 0 ? 'out' : 'in';
}

function bankStatementBuildExternalId(string $bank, string $source, array $txn): string
{
    $base = implode('|', [
        $bank,
        $source,
        $txn['transaction_date'] ?? '',
        $txn['transaction_type'] ?? '',
        number_format((float) ($txn['amount'] ?? 0), 2, '.', ''),
        mb_strtolower((string) ($txn['description'] ?? ''), 'UTF-8'),
        mb_strtolower((string) ($txn['reference'] ?? ''), 'UTF-8'),
    ]);

    return sha1($base);
}

function bankStatementParseCsv(string $path): array
{
    $firstLine = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? '';
    $delimiterCounts = [
        ';' => substr_count($firstLine, ';'),
        ',' => substr_count($firstLine, ','),
        "\t" => substr_count($firstLine, "\t"),
    ];
    arsort($delimiterCounts);
    $delimiter = (string) array_key_first($delimiterCounts);

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Nao foi possivel ler o arquivo CSV.');
    }

    $headers = fgetcsv($handle, 0, $delimiter);
    if (!is_array($headers)) {
        fclose($handle);
        return [];
    }

    $headers = array_map(static fn ($header) => bankStatementNormalizeHeader((string) $header), $headers);
    $transactions = [];

    while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (!is_array($values) || count(array_filter($values, static fn ($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }

        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = $values[$index] ?? '';
        }

        $date = bankStatementParseDate(bankStatementFirstValue($row, [
            'data',
            'data_lancamento',
            'data_do_lancamento',
            'dt',
            'date',
        ]));
        $description = bankStatementFirstValue($row, [
            'descricao',
            'historico',
            'lancamento',
            'description',
            'memo',
        ]);
        $reference = bankStatementFirstValue($row, [
            'referencia',
            'documento',
            'doc',
            'id',
            'identificador',
            'numero',
        ]);
        $rawType = bankStatementFirstValue($row, ['tipo', 'natureza', 'debito_credito', 'd_c', 'dc']);
        $signedAmount = bankStatementParseAmount(bankStatementFirstValue($row, ['valor', 'amount', 'vlr', 'valor_lancamento']));

        if ($date === '' || $description === '' || abs($signedAmount) <= 0) {
            continue;
        }

        $transactions[] = [
            'transaction_date' => $date,
            'transaction_type' => bankStatementResolveType($rawType, $signedAmount),
            'description' => mb_substr($description, 0, 160, 'UTF-8'),
            'reference' => $reference !== '' ? mb_substr($reference, 0, 120, 'UTF-8') : null,
            'amount' => abs($signedAmount),
            'raw_payload' => $row,
        ];
    }

    fclose($handle);
    return $transactions;
}

function bankStatementOfxTag(string $content, string $tag): string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]+)/i', $content, $match)) {
        return trim($match[1]);
    }

    return '';
}

function bankStatementParseOfx(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Nao foi possivel ler o arquivo OFX.');
    }

    preg_match_all('/<STMTTRN>(.*?)(?=<STMTTRN>|<\/BANKTRANLIST>|<\/CREDITCARDMSGSRSV1>)/is', $content, $matches);
    $transactions = [];

    foreach ($matches[1] ?? [] as $block) {
        $date = bankStatementParseDate(substr(bankStatementOfxTag($block, 'DTPOSTED'), 0, 8));
        $signedAmount = bankStatementParseAmount(bankStatementOfxTag($block, 'TRNAMT'));
        $name = bankStatementOfxTag($block, 'NAME');
        $memo = bankStatementOfxTag($block, 'MEMO');
        $description = trim($name . ($memo !== '' && $memo !== $name ? ' - ' . $memo : ''));
        $reference = bankStatementOfxTag($block, 'FITID') ?: bankStatementOfxTag($block, 'CHECKNUM');
        $rawType = bankStatementOfxTag($block, 'TRNTYPE');

        if ($date === '' || $description === '' || abs($signedAmount) <= 0) {
            continue;
        }

        $transactions[] = [
            'transaction_date' => $date,
            'transaction_type' => bankStatementResolveType($rawType, $signedAmount),
            'description' => mb_substr($description, 0, 160, 'UTF-8'),
            'reference' => $reference !== '' ? mb_substr($reference, 0, 120, 'UTF-8') : null,
            'amount' => abs($signedAmount),
            'raw_payload' => [
                'fitid' => $reference,
                'trntype' => $rawType,
                'trnamt' => $signedAmount,
                'name' => $name,
                'memo' => $memo,
            ],
        ];
    }

    return $transactions;
}

function bankStatementParseFile(string $path, string $source): array
{
    if ($source === 'ofx') {
        return bankStatementParseOfx($path);
    }

    return bankStatementParseCsv($path);
}

function bankStatementImport(PDO $pdo, string $bank, string $source, array $transactions): array
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO bank_transactions
            (bank, external_id, source, transaction_type, description, amount, transaction_date, reference, raw_payload)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $imported = 0;
    $duplicated = 0;

    foreach ($transactions as $txn) {
        $externalId = bankStatementBuildExternalId($bank, $source, $txn);
        $stmt->execute([
            $bank,
            $externalId,
            $source,
            $txn['transaction_type'],
            $txn['description'],
            $txn['amount'],
            $txn['transaction_date'],
            $txn['reference'] ?? null,
            json_encode($txn['raw_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if ($stmt->rowCount() > 0) {
            $imported++;
        } else {
            $duplicated++;
        }
    }

    return [
        'read' => count($transactions),
        'imported' => $imported,
        'duplicated' => $duplicated,
    ];
}
