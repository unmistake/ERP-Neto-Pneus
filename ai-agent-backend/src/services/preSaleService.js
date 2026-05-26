import { config } from '../config/env.js';
import { openai } from './openaiClient.js';
import { erpGet, erpPatch } from './erpApiService.js';

function parseJsonLoose(text) {
  const raw = String(text || '').trim();
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    const start = raw.indexOf('{');
    const end = raw.lastIndexOf('}');
    if (start >= 0 && end > start) {
      const slice = raw.slice(start, end + 1);
      try {
        return JSON.parse(slice);
      } catch {
        return null;
      }
    }
    return null;
  }
}

function normalizeText(value) {
  return String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9 ]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function normalizeBrand(brand) {
  const b = normalizeText(brand);
  const aliases = {
    LANVIAGTOR: 'LANVIGATOR',
    LAMVIGATOR: 'LANVIGATOR',
    ALNVIGATOR: 'LANVIGATOR',
    LIKEEN: 'LYKEEN',
    LYKEN: 'LYKEEN',
    WESTALKE: 'WESTLAKE',
    DOAVROAD: 'DOVROAD',
    APATNY: 'APTANY',
    GOLDEN: 'GOLD',
    MILEVER: 'MILIVER',
  };
  return aliases[b] || b;
}

function parseMoneyBr(value) {
  if (typeof value === 'number') return value;
  const txt = String(value || '').trim();
  if (!txt) return 0;
  return Number(txt.replace(/\./g, '').replace(',', '.')) || 0;
}

function extractMeasure(text) {
  const match = normalizeText(text).match(/(\d{3})\s*[\/ ]\s*(\d{2})\s*R\s*(\d{2})/i);
  if (!match) return null;
  return { width: match[1], profile: match[2], rim: match[3] };
}

function tokenizeModel(value) {
  return normalizeText(value)
    .split(' ')
    .filter((t) => t && !/^(PNEU|PNEUS|UNI|UNID|IMP|TB|RS|SC|XL|AT|HP|SPORT)$/.test(t));
}

function scoreMatch(item, product) {
  let score = 0;
  if (String(product.width || '') === item.width) score += 4;
  if (String(product.profile || '') === item.profile) score += 4;
  if (String(product.rim || '') === item.rim) score += 4;

  const itemBrand = normalizeBrand(item.brand);
  const productBrand = normalizeBrand(product.brand);
  if (itemBrand && productBrand && itemBrand === productBrand) score += 4;

  const itemTokens = tokenizeModel(item.model);
  const productTokens = tokenizeModel(product.model);
  if (itemTokens.length && productTokens.length) {
    const overlap = itemTokens.filter((t) => productTokens.includes(t)).length;
    score += overlap;
  }
  return score;
}

async function listAllProducts() {
  const out = [];
  let page = 1;
  let hasNext = true;
  while (hasNext) {
    const resp = await erpGet('products', { page, limit: 200 });
    const data = Array.isArray(resp?.data) ? resp.data : [];
    out.push(...data);
    hasNext = Boolean(resp?.pagination?.has_next);
    page += 1;
  }
  return out;
}

async function extractItemsWithVision(imageBuffer, mimeType = 'image/jpeg') {
  const b64 = imageBuffer.toString('base64');
  const prompt = `
Extraia os itens de pneus de uma pre-venda/orcamento e retorne APENAS JSON valido:
{
  "items":[
    {"description":"...", "width":"195", "profile":"55", "rim":"15", "brand":"DOVROAD", "model":"ZYPHIRA", "unit_cost":235.0}
  ]
}
Regras:
- usar unit_cost como numero decimal (sem R$)
- preencher width/profile/rim sempre que possivel
- marca em brand e restante em model
- ignorar itens que nao sejam pneus
`;

  if (config.openaiApiKey) {
    try {
      const response = await openai.chat.completions.create({
        model: config.openaiModel,
        messages: [
          {
            role: 'user',
            content: [
              { type: 'text', text: prompt },
              { type: 'image_url', image_url: { url: `data:${mimeType};base64,${b64}` } },
            ],
          },
        ],
        response_format: { type: 'json_object' },
      });
      const content = response?.choices?.[0]?.message?.content || '{}';
      const parsed = JSON.parse(content);
      return Array.isArray(parsed?.items) ? parsed.items : [];
    } catch (error) {
      const isQuotaLike = error?.status === 429 || error?.code === 'insufficient_quota' || error?.type === 'insufficient_quota';
      if (!config.ollamaVisionModel || !isQuotaLike) {
        throw error;
      }
      // segue para fallback Ollama vision
    }
  }

  if (config.ollamaVisionModel) {
    const ollamaResp = await fetch(`${config.ollamaBaseUrl}/api/generate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: config.ollamaVisionModel,
        prompt,
        images: [b64],
        stream: false,
      }),
    });
    if (!ollamaResp.ok) {
      const details = await ollamaResp.text();
      const err = new Error(`Ollama vision falhou: HTTP ${ollamaResp.status} ${details}`);
      err.code = 'vision_ollama_http_error';
      throw err;
    }
    const body = await ollamaResp.json();
    const content = String(body?.response || '{}').trim();
    const parsed = parseJsonLoose(content);
    if (!parsed) {
      const err = new Error('Ollama vision retornou conteudo nao-JSON.');
      err.code = 'vision_ollama_parse_error';
      throw err;
    }
    return Array.isArray(parsed?.items) ? parsed.items : [];
  }

  const err = new Error('Nenhum provedor de visao configurado. Defina OPENAI_API_KEY ou OLLAMA_VISION_MODEL.');
  err.code = 'vision_provider_not_configured';
  throw err;
}

export async function processPreSaleImageAndUpdateCosts(imageBuffer, mimeType = 'image/jpeg') {
  const rawItems = await extractItemsWithVision(imageBuffer, mimeType);
  const parsedItems = rawItems
    .map((it) => {
      const measure = it.width && it.profile && it.rim ? { width: String(it.width), profile: String(it.profile), rim: String(it.rim) } : extractMeasure(it.description || '');
      if (!measure) return null;
      const description = String(it.description || '').trim();
      const brandFromDesc = normalizeText(description).split(' ').find((t) => /^[A-Z]{3,}$/.test(t)) || '';
      const brand = normalizeBrand(it.brand || brandFromDesc);
      const unitCost = parseMoneyBr(it.unit_cost);
      if (!unitCost || unitCost <= 0) return null;
      return {
        description,
        width: measure.width,
        profile: measure.profile,
        rim: measure.rim,
        brand,
        model: String(it.model || '').trim(),
        unit_cost: unitCost,
      };
    })
    .filter(Boolean);

  if (parsedItems.length === 0) {
    const err = new Error('Nao foi possivel extrair itens de pneus da imagem.');
    err.code = 'pre_sale_no_items_extracted';
    throw err;
  }

  const products = await listAllProducts();
  const stockTires = products.filter((p) => String(p.category || '').toLowerCase() === 'pneu');

  const updates = [];
  const notFound = [];
  const skippedCostFilled = [];

  for (const item of parsedItems) {
    let best = null;
    let bestScore = -1;
    for (const product of stockTires) {
      const score = scoreMatch(item, product);
      if (score > bestScore) {
        bestScore = score;
        best = product;
      }
    }
    if (!best || bestScore < 12) {
      notFound.push(item);
      continue;
    }

    const currentCost = Number(best.cost_price || 0);
    if (currentCost > 0) {
      skippedCostFilled.push({ item, product: best });
      continue;
    }

    const patchResp = await erpPatch(`products/${best.id}/cost`, { cost_price: item.unit_cost });
    updates.push({
      product_id: best.id,
      name: best.name,
      new_cost: patchResp?.data?.cost_price ?? item.unit_cost,
      source: item.description,
    });
  }

  return {
    extracted_count: parsedItems.length,
    updated_count: updates.length,
    skipped_already_filled_count: skippedCostFilled.length,
    not_found_count: notFound.length,
    updates,
    not_found: notFound.slice(0, 10),
  };
}
