<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/customer_geocode.php';

$customers = customerGeocodeFetchForMap($pdo);
$stats = customerGeocodeStats($pdo);

// Monta os pontos para o JS e o conjunto de UFs presentes (para o filtro).
$points = [];
$states = [];
foreach ($customers as $customer) {
    $uf = strtoupper(trim((string) ($customer['address_state'] ?? '')));
    if ($uf !== '' && !in_array($uf, $states, true)) {
        $states[] = $uf;
    }

    $addressLine = trim(implode(', ', array_filter([
        trim((string) $customer['address_street'] . ' ' . (string) $customer['address_number']),
        (string) $customer['address_district'],
        trim((string) $customer['address_city'] . ($uf !== '' ? ' - ' . $uf : '')),
    ])));

    $points[] = [
        'id' => (int) $customer['id'],
        'name' => trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']),
        'lat' => (float) $customer['geo_latitude'],
        'lng' => (float) $customer['geo_longitude'],
        'precision' => (string) ($customer['geo_precision'] ?? ''),
        'uf' => $uf,
        'phone' => (string) ($customer['phone'] ?? ''),
        'car' => (string) ($customer['car'] ?? ''),
        'address' => $addressLine,
        'sales' => (int) $customer['sales_count'],
        'salesTotal' => (float) $customer['sales_total'],
    ];
}
sort($states);

$cards = [
    ['label' => 'Clientes', 'value' => $stats['total'], 'tone' => 'text-slate-900'],
    ['label' => 'Com endereco', 'value' => $stats['with_address'], 'tone' => 'text-slate-900'],
    ['label' => 'No mapa', 'value' => $stats['located'], 'tone' => 'text-emerald-600'],
    ['label' => 'Pendentes', 'value' => $stats['pending'], 'tone' => 'text-amber-600'],
];
?>
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin=""
/>

<div class="space-y-5">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.24em] text-emerald-600">Neto Rodas</p>
            <h1 class="mt-1 text-2xl font-black text-slate-900 sm:text-3xl">Mapa de Clientes</h1>
            <p class="mt-1 text-sm text-slate-500">Onde moram os clientes, com zoom e referencias reais de ruas e cidades.</p>
        </div>
        <form method="post" action="actions/customer_geocode_sync.php" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="limit" value="50">
            <button
                type="submit"
                class="rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-bold text-slate-950 shadow-lg shadow-emerald-950/20 transition hover:bg-emerald-400"
            >
                Sincronizar localizacoes<?= $stats['pending'] > 0 ? ' (' . (int) $stats['pending'] . ' pendente)' : '' ?>
            </button>
            <button
                type="submit"
                name="mode"
                value="full"
                class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
                title="Refaz a geocodificacao de todos os clientes com endereco"
            >
                Recalcular tudo
            </button>
        </form>
    </header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?php foreach ($cards as $card): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= htmlspecialchars($card['label']) ?></p>
                <p class="mt-1 text-2xl font-black <?= $card['tone'] ?>"><?= (int) $card['value'] ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($stats['located'] === 0): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-800">
            Nenhum cliente geolocalizado ainda. Clique em <strong>Sincronizar localizacoes</strong> para posicionar no mapa
            os <?= (int) $stats['with_address'] ?> cliente(s) que possuem endereco.
        </div>
    <?php endif; ?>

    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2" data-uf-filter>
                <button type="button" data-uf="*" class="rounded-full bg-slate-900 px-3 py-1.5 text-xs font-bold text-white">Todos</button>
                <?php foreach ($states as $uf): ?>
                    <button type="button" data-uf="<?= htmlspecialchars($uf) ?>" class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-200"><?= htmlspecialchars($uf) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs font-semibold text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-emerald-500"></span>Rua</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-amber-500"></span>Cidade</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-sky-500"></span>CEP</span>
            </div>
        </div>
        <div id="customer-map" class="h-[68vh] min-h-[420px] w-full overflow-hidden rounded-xl"></div>
    </div>
</div>

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>
<script>
(() => {
    const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
    const el = document.getElementById('customer-map');
    if (!el || typeof L === 'undefined') {
        return;
    }

    const colors = { rua: '#10b981', cidade: '#f59e0b', cep: '#0ea5e9' };

    const map = L.map(el, { scrollWheelZoom: true }).setView([-14.235, -51.925], 4);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    L.control.scale({ imperial: false, metric: true }).addTo(map);

    const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const markers = [];

    points.forEach((p) => {
        if (!Number.isFinite(p.lat) || !Number.isFinite(p.lng)) {
            return;
        }

        const color = colors[p.precision] || '#64748b';
        const marker = L.circleMarker([p.lat, p.lng], {
            radius: 8,
            color: '#ffffff',
            weight: 2,
            fillColor: color,
            fillOpacity: 0.92,
        });

        const lines = [
            `<strong style="font-size:13px">${escapeHtml(p.name)}</strong>`,
            p.address ? `<div style="color:#475569">${escapeHtml(p.address)}</div>` : '',
            p.phone ? `<div>Tel: ${escapeHtml(p.phone)}</div>` : '',
            p.car ? `<div>Carro: ${escapeHtml(p.car)}</div>` : '',
            `<div style="margin-top:4px">Vendas: <strong>${p.sales}</strong>${p.salesTotal > 0 ? ' &middot; ' + brl.format(p.salesTotal) : ''}</div>`,
            `<div style="color:#94a3b8;font-size:11px">Precisao: ${escapeHtml(p.precision || 'n/d')}</div>`,
        ].filter(Boolean);

        marker.bindPopup(lines.join(''), { maxWidth: 260 });
        marker.uf = p.uf;
        marker.addTo(map);
        markers.push(marker);
    });

    if (markers.length) {
        const group = L.featureGroup(markers);
        map.fitBounds(group.getBounds().pad(0.2));
    }

    // Filtro por UF.
    const filterBar = document.querySelector('[data-uf-filter]');
    if (filterBar) {
        filterBar.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-uf]');
            if (!button) {
                return;
            }

            const uf = button.dataset.uf;
            filterBar.querySelectorAll('button').forEach((b) => {
                const active = b === button;
                b.classList.toggle('bg-slate-900', active);
                b.classList.toggle('text-white', active);
                b.classList.toggle('bg-slate-100', !active);
                b.classList.toggle('text-slate-600', !active);
            });

            const visible = [];
            markers.forEach((m) => {
                const show = uf === '*' || m.uf === uf;
                if (show) {
                    m.addTo(map);
                    visible.push(m);
                } else {
                    map.removeLayer(m);
                }
            });

            if (visible.length) {
                map.fitBounds(L.featureGroup(visible).getBounds().pad(0.2));
            }
        });
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }
})();
</script>
