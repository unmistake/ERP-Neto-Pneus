<?php

declare(strict_types=1);

require_once __DIR__ . '/customer_schema.php';

/**
 * Geocodificacao de clientes para o mapa interativo.
 *
 * Estrategia: tenta o endereco completo (rua/numero) no Nominatim/OpenStreetMap,
 * com fallback para cidade/UF. Quando a rua esta ausente mas existe CEP, enriquece
 * o endereco pelo ViaCEP antes de geocodificar. Os resultados ficam em cache nas
 * colunas geo_* da tabela customers para evitar consultas externas repetidas.
 *
 * Politica de uso do Nominatim: maximo de 1 requisicao por segundo e User-Agent
 * identificavel. Por isso a sincronizacao roda em lote, com pausa entre chamadas.
 */

const CUSTOMER_GEOCODE_USER_AGENT = 'ERP-NetoRodas/1.0 (mapa-clientes)';
const CUSTOMER_GEOCODE_NOMINATIM = 'https://nominatim.openstreetmap.org/search';
const CUSTOMER_GEOCODE_VIACEP = 'https://viacep.com.br/ws/%s/json/';

function customerGeocodeEnsureSchema(PDO $pdo): void
{
    ensureCustomerAddressSchema($pdo);

    $columns = [
        'geo_latitude' => 'ALTER TABLE customers ADD COLUMN geo_latitude DECIMAL(10,7) NULL AFTER address_country',
        'geo_longitude' => 'ALTER TABLE customers ADD COLUMN geo_longitude DECIMAL(10,7) NULL AFTER geo_latitude',
        'geo_precision' => 'ALTER TABLE customers ADD COLUMN geo_precision VARCHAR(20) NULL AFTER geo_longitude',
        'geo_label' => 'ALTER TABLE customers ADD COLUMN geo_label VARCHAR(255) NULL AFTER geo_precision',
        'geo_status' => 'ALTER TABLE customers ADD COLUMN geo_status VARCHAR(20) NULL AFTER geo_label',
        'geo_updated_at' => 'ALTER TABLE customers ADD COLUMN geo_updated_at DATETIME NULL AFTER geo_status',
    ];

    foreach ($columns as $column => $sql) {
        $exists = (bool) $pdo->query('SHOW COLUMNS FROM customers LIKE ' . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }
}

function customerGeocodeHasAddress(array $customer): bool
{
    $city = trim((string) ($customer['address_city'] ?? ''));
    $zip = preg_replace('/\D/', '', (string) ($customer['address_zip'] ?? '')) ?? '';
    $street = trim((string) ($customer['address_street'] ?? ''));

    return $city !== '' || strlen($zip) === 8 || $street !== '';
}

/**
 * Executa um GET simples e retorna o corpo decodificado em JSON (ou null).
 */
function customerGeocodeHttpGetJson(string $url): ?array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_USERAGENT, CUSTOMER_GEOCODE_USER_AGENT);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Accept-Language: pt-BR']);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $status < 200 || $status >= 300) {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Quando a rua esta ausente mas ha CEP valido, busca os dados no ViaCEP.
 * Retorna campos normalizados (street, district, city, state) ou null.
 */
function customerGeocodeViaCep(string $zip): ?array
{
    $digits = preg_replace('/\D/', '', $zip) ?? '';
    if (strlen($digits) !== 8) {
        return null;
    }

    $data = customerGeocodeHttpGetJson(sprintf(CUSTOMER_GEOCODE_VIACEP, $digits));
    if ($data === null || !empty($data['erro'])) {
        return null;
    }

    return [
        'street' => trim((string) ($data['logradouro'] ?? '')),
        'district' => trim((string) ($data['bairro'] ?? '')),
        'city' => trim((string) ($data['localidade'] ?? '')),
        'state' => trim((string) ($data['uf'] ?? '')),
    ];
}

/**
 * Consulta estruturada no Nominatim. Retorna ['lat','lng','label'] ou null.
 */
function customerGeocodeNominatim(array $params): ?array
{
    $query = array_merge([
        'format' => 'jsonv2',
        'addressdetails' => '0',
        'limit' => '1',
        'countrycodes' => 'br',
    ], $params);

    $results = customerGeocodeHttpGetJson(CUSTOMER_GEOCODE_NOMINATIM . '?' . http_build_query($query));
    if ($results === null || !isset($results[0]['lat'], $results[0]['lon'])) {
        return null;
    }

    $lat = (float) $results[0]['lat'];
    $lng = (float) $results[0]['lon'];
    if ($lat === 0.0 && $lng === 0.0) {
        return null;
    }

    return [
        'lat' => $lat,
        'lng' => $lng,
        'label' => trim((string) ($results[0]['display_name'] ?? '')),
    ];
}

/**
 * Resolve as coordenadas de um cliente seguindo a cadeia rua -> cidade.
 * Retorna ['lat','lng','precision','label'] ou null se nada for encontrado.
 */
function customerGeocodeResolve(array $customer): ?array
{
    $street = trim((string) ($customer['address_street'] ?? ''));
    $number = trim((string) ($customer['address_number'] ?? ''));
    $city = trim((string) ($customer['address_city'] ?? ''));
    $state = trim((string) ($customer['address_state'] ?? ''));
    $zip = trim((string) ($customer['address_zip'] ?? ''));

    // Enriquecimento via ViaCEP quando faltar rua ou cidade.
    if (($street === '' || $city === '') && preg_replace('/\D/', '', $zip)) {
        $viaCep = customerGeocodeViaCep($zip);
        if ($viaCep !== null) {
            if ($street === '') {
                $street = $viaCep['street'];
            }
            if ($city === '') {
                $city = $viaCep['city'];
            }
            if ($state === '') {
                $state = $viaCep['state'];
            }
        }
    }

    // 1) Endereco completo com rua e numero (precisao maxima).
    if ($street !== '' && $city !== '') {
        $streetQuery = $number !== '' ? $number . ' ' . $street : $street;
        $hit = customerGeocodeNominatim([
            'street' => $streetQuery,
            'city' => $city,
            'state' => $state,
            'country' => 'Brasil',
        ]);
        if ($hit !== null) {
            $hit['precision'] = 'rua';
            return $hit;
        }
    }

    // 2) Apenas a cidade/UF (precisao de cidade).
    if ($city !== '') {
        $hit = customerGeocodeNominatim([
            'city' => $city,
            'state' => $state,
            'country' => 'Brasil',
        ]);
        if ($hit !== null) {
            $hit['precision'] = 'cidade';
            return $hit;
        }
    }

    // 3) Ultimo recurso: o proprio CEP como texto livre.
    $zipDigits = preg_replace('/\D/', '', $zip) ?? '';
    if (strlen($zipDigits) === 8) {
        $hit = customerGeocodeNominatim([
            'q' => substr($zipDigits, 0, 5) . '-' . substr($zipDigits, 5) . ', Brasil',
        ]);
        if ($hit !== null) {
            $hit['precision'] = 'cep';
            return $hit;
        }
    }

    return null;
}

/**
 * Geocodifica clientes que ainda nao possuem coordenadas (ou todos quando $force).
 * Roda em lote limitado por $limit, com pausa de 1s entre chamadas externas.
 *
 * @return array{processed:int,located:int,failed:int,remaining:int}
 */
function customerGeocodeSyncPending(PDO $pdo, int $limit = 25, bool $force = false): array
{
    customerGeocodeEnsureSchema($pdo);

    $limit = max(1, min(100, $limit));

    $where = $force
        ? "(address_city <> '' OR address_zip <> '' OR address_street <> '')"
        : "geo_latitude IS NULL AND (address_city <> '' OR address_zip <> '' OR address_street <> '')";

    $stmt = $pdo->query(
        'SELECT id, address_street, address_number, address_district, address_city, address_state, address_zip
         FROM customers
         WHERE ' . $where . '
         ORDER BY id ASC
         LIMIT ' . $limit
    );
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare(
        'UPDATE customers
         SET geo_latitude = ?, geo_longitude = ?, geo_precision = ?, geo_label = ?, geo_status = ?, geo_updated_at = NOW()
         WHERE id = ?'
    );

    $located = 0;
    $failed = 0;

    foreach ($customers as $index => $customer) {
        // Respeita o limite de 1 requisicao por segundo do Nominatim.
        if ($index > 0) {
            sleep(1);
        }

        $result = customerGeocodeResolve($customer);

        if ($result === null) {
            $update->execute([null, null, null, null, 'falhou', (int) $customer['id']]);
            $failed++;
            continue;
        }

        $update->execute([
            $result['lat'],
            $result['lng'],
            $result['precision'],
            mb_substr($result['label'], 0, 255),
            'ok',
            (int) $customer['id'],
        ]);
        $located++;
    }

    $remainingStmt = $pdo->query(
        "SELECT COUNT(*) FROM customers
         WHERE geo_latitude IS NULL AND (address_city <> '' OR address_zip <> '' OR address_street <> '')"
    );

    return [
        'processed' => count($customers),
        'located' => $located,
        'failed' => $failed,
        'remaining' => (int) $remainingStmt->fetchColumn(),
    ];
}

/**
 * Clientes geolocalizados com contagem de vendas, para alimentar o mapa.
 *
 * @return array<int,array<string,mixed>>
 */
function customerGeocodeFetchForMap(PDO $pdo): array
{
    customerGeocodeEnsureSchema($pdo);

    $stmt = $pdo->query(
        "SELECT c.id, c.first_name, c.last_name, c.phone, c.car,
                c.address_street, c.address_number, c.address_district,
                c.address_city, c.address_state, c.address_zip,
                c.geo_latitude, c.geo_longitude, c.geo_precision, c.geo_label,
                COUNT(s.id) AS sales_count,
                COALESCE(SUM(s.total_amount), 0) AS sales_total
         FROM customers c
         LEFT JOIN sales s ON s.customer_id = c.id
         WHERE c.geo_latitude IS NOT NULL AND c.geo_longitude IS NOT NULL
         GROUP BY c.id
         ORDER BY c.first_name ASC, c.last_name ASC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Estatisticas de cobertura geografica para os cards da pagina.
 *
 * @return array{total:int,with_address:int,located:int,pending:int,failed:int}
 */
function customerGeocodeStats(PDO $pdo): array
{
    customerGeocodeEnsureSchema($pdo);

    $row = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN (address_city <> '' OR address_zip <> '' OR address_street <> '') THEN 1 ELSE 0 END) AS with_address,
            SUM(CASE WHEN geo_latitude IS NOT NULL THEN 1 ELSE 0 END) AS located,
            SUM(CASE WHEN geo_latitude IS NULL AND (address_city <> '' OR address_zip <> '' OR address_street <> '') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN geo_status = 'falhou' THEN 1 ELSE 0 END) AS failed
         FROM customers"
    )->fetch(PDO::FETCH_ASSOC);

    return [
        'total' => (int) ($row['total'] ?? 0),
        'with_address' => (int) ($row['with_address'] ?? 0),
        'located' => (int) ($row['located'] ?? 0),
        'pending' => (int) ($row['pending'] ?? 0),
        'failed' => (int) ($row['failed'] ?? 0),
    ];
}
