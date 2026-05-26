import { config } from '../config/env.js';
import { openai } from './openaiClient.js';
import { generateOllamaReply } from './ollamaService.js';
import { listTools, runTool } from '../tools/index.js';

function toYmd(date) {
  return date.toISOString().slice(0, 10);
}

function moneyBr(value) {
  return `R$ ${Number(value).toFixed(2).replace('.', ',')}`;
}

function formatDateBr(ymd) {
  const [y, m, d] = ymd.split('-');
  return `${d}/${m}/${y}`;
}

function startOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function endOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0);
}

function parseBrDateToYmd(brDate) {
  const m = brDate.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  if (!m) return null;
  const [, dd, mm, yyyy] = m;
  return `${yyyy}-${mm}-${dd}`;
}

function parsePeriodFromMessage(userMessage) {
  const text = String(userMessage || '').toLowerCase();
  const now = new Date();

  if (/(antes de ontem|anteontem)/i.test(text)) {
    const d = new Date(now);
    d.setDate(d.getDate() - 2);
    const ymd = toYmd(d);
    return { label: `Antes de ontem (${formatDateBr(ymd)})`, dateFrom: ymd, dateTo: ymd };
  }

  if (/\bontem\b/i.test(text)) {
    const d = new Date(now);
    d.setDate(d.getDate() - 1);
    const ymd = toYmd(d);
    return { label: `Ontem (${formatDateBr(ymd)})`, dateFrom: ymd, dateTo: ymd };
  }

  if (/\bhoje\b/i.test(text)) {
    const ymd = toYmd(now);
    return { label: `Hoje (${formatDateBr(ymd)})`, dateFrom: ymd, dateTo: ymd };
  }

  const lastDaysMatch = text.match(/(?:ultimos|últimos)\s+(\d{1,3})\s+dias?/i);
  if (lastDaysMatch) {
    const days = Math.max(1, Number(lastDaysMatch[1]));
    const start = new Date(now);
    start.setDate(start.getDate() - (days - 1));
    return {
      label: `Nos ultimos ${days} dias (${formatDateBr(toYmd(start))} a ${formatDateBr(toYmd(now))})`,
      dateFrom: toYmd(start),
      dateTo: toYmd(now),
    };
  }

  if (/(esse mes|este mes|m[eê]s atual)/i.test(text)) {
    const from = startOfMonth(now);
    return {
      label: `Neste mes (${formatDateBr(toYmd(from))} a ${formatDateBr(toYmd(now))})`,
      dateFrom: toYmd(from),
      dateTo: toYmd(now),
    };
  }

  if (/(mes passado|m[eê]s passado)/i.test(text)) {
    const firstCurrent = startOfMonth(now);
    const lastPrev = new Date(firstCurrent.getTime() - 24 * 60 * 60 * 1000);
    const firstPrev = startOfMonth(lastPrev);
    return {
      label: `No mes passado (${formatDateBr(toYmd(firstPrev))} a ${formatDateBr(toYmd(lastPrev))})`,
      dateFrom: toYmd(firstPrev),
      dateTo: toYmd(lastPrev),
    };
  }

  const betweenBr = text.match(/(?:de|do)\s+(\d{2}\/\d{2}\/\d{4})\s+(?:a|ate|até)\s+(\d{2}\/\d{2}\/\d{4})/i);
  if (betweenBr) {
    const from = parseBrDateToYmd(betweenBr[1]);
    const to = parseBrDateToYmd(betweenBr[2]);
    if (from && to) {
      return {
        label: `No periodo de ${formatDateBr(from)} a ${formatDateBr(to)}`,
        dateFrom: from,
        dateTo: to,
      };
    }
  }

  const betweenYmd = text.match(/(?:de|do)\s+(\d{4}-\d{2}-\d{2})\s+(?:a|ate|até)\s+(\d{4}-\d{2}-\d{2})/i);
  if (betweenYmd) {
    return {
      label: `No periodo de ${formatDateBr(betweenYmd[1])} a ${formatDateBr(betweenYmd[2])}`,
      dateFrom: betweenYmd[1],
      dateTo: betweenYmd[2],
    };
  }

  const specificBr = text.match(/(?:em|no dia)\s+(\d{2}\/\d{2}\/\d{4})/i);
  if (specificBr) {
    const ymd = parseBrDateToYmd(specificBr[1]);
    if (ymd) {
      return { label: `Em ${formatDateBr(ymd)}`, dateFrom: ymd, dateTo: ymd };
    }
  }

  const specificYmd = text.match(/(?:em|no dia)\s+(\d{4}-\d{2}-\d{2})/i);
  if (specificYmd) {
    const ymd = specificYmd[1];
    return { label: `Em ${formatDateBr(ymd)}`, dateFrom: ymd, dateTo: ymd };
  }

  return null;
}

function parseKeyValuePairs(raw) {
  const pairs = {};
  raw.split(',').forEach((part) => {
    const [k, ...rest] = part.split('=');
    if (!k || rest.length === 0) return;
    pairs[k.trim().toLowerCase()] = rest.join('=').trim();
  });
  return pairs;
}

function parseSaleItems(raw) {
  // formato: itens=1x2@499.9;3x1@120
  return raw
    .split(';')
    .map((chunk) => chunk.trim())
    .filter(Boolean)
    .map((chunk) => {
      const m = chunk.match(/^(\d+)x(\d+)@([\d.,]+)$/);
      if (!m) return null;
      return {
        product_id: Number(m[1]),
        quantity: Number(m[2]),
        unit_price: Number(m[3].replace(',', '.')),
      };
    })
    .filter(Boolean);
}

function parseStockItems(raw) {
  // formato: 1x2#categoria;3x1#categoria  (categoria opcional)
  return raw
    .split(';')
    .map((chunk) => chunk.trim())
    .filter(Boolean)
    .map((chunk) => {
      const m = chunk.match(/^(\d+)x(\d+)(?:#([\w\- ]+))?$/i);
      if (!m) return null;
      return {
        product_id: Number(m[1]),
        quantity: Number(m[2]),
        category: (m[3] || '').trim(),
      };
    })
    .filter(Boolean);
}

function normalizeWhitespace(v) {
  return String(v || '')
    .replace(/\s+/g, ' ')
    .trim();
}

function normalizeTireLine(rawLine) {
  let line = normalizeWhitespace(rawLine);
  if (!line) return '';
  // Remove prefixo de timestamp do WhatsApp, se existir.
  line = line.replace(/^\[\d{1,2}:\d{2},\s*\d{2}\/\d{2}\/\d{4}\]\s*[^:]+:\s*/i, '');
  // Normaliza abreviacoes comuns de quantidade (ex.: 1p, 2p, 1pneu225/...).
  line = line.replace(/^(\d+)\s*pneu(?=\d)/i, '$1 pneu ');
  line = line.replace(/^(\d+)\s*p\b/i, '$1 pneu ');
  line = line.replace(/^(\d+)\s*pneu\b/i, '$1 pneu ');
  line = line.replace(/\s*-\s*/g, ' ');
  line = line.replace(/\s+/g, ' ').trim();
  return line;
}

function normalizeLocation(rawLocation) {
  const loc = normalizeWhitespace(rawLocation);
  const lower = loc.toLowerCase();
  if (!loc) return '';
  if (lower.includes('andar de cima da loja')) return 'Depósito';
  return loc;
}

function normalizeBrand(rawBrand) {
  const input = String(rawBrand || '').toUpperCase();
  const aliases = {
    LANVIGATOR: 'LANVIGATOR',
    LANVIAGTOR: 'LANVIGATOR',
    LAMVIGATOR: 'LANVIGATOR',
    ALNVIGATOR: 'LANVIGATOR',
    CONFOT: 'CONFORT',
    COMFORT: 'CONFORT',
    LIKEEN: 'LYKEEN',
    LYKEN: 'LYKEEN',
    LYKEEN: 'LYKEEN',
    WESTALKE: 'WESTLAKE',
    DOAVROAD: 'DOVROAD',
    APATNY: 'APTANY',
    'GOLD CROW': 'GOLD CROWN',
    'GOLDEN CROWN': 'GOLD CROWN',
    MILEVER: 'MILIVER',
    MILIVER: 'MILIVER',
  };
  return aliases[input] || input;
}

function parseTireBatch(message) {
  const lines = String(message || '')
    .split(/\r?\n/)
    .map(normalizeTireLine)
    .filter(Boolean);

  let defaultLocation = '';
  const parsed = [];

  for (const raw of lines) {
    const lower = raw.toLowerCase();
    const localInline = raw.match(/(?:^|,\s*)no local\s+(.+?)(?:\s+o seguinte:?)?$/i);
    if (localInline) {
      defaultLocation = normalizeLocation(localInline[1]);
      continue;
    }
    if (lower.includes('localizado') || lower.includes('localizados')) {
      defaultLocation = normalizeLocation(raw.replace(/^.*localizados?\s*/i, ''));
      continue;
    }

    // Ex.: "4 pneus 205/45 R17 Lanvigator"
    const m = raw.match(/^\s*(\d+)\s*(?:pne\w*|p)\s+(.+)$/i);
    if (!m) continue;

    const qty = Number(m[1]);
    let rest = normalizeWhitespace(m[2]);

    // Medida: 205/45 R17, 245:70 R16 e variantes com sufixo de carga (ex: R14 C).
    const sizeMatch = rest.match(/(\d{3})\s*[\/:]\s*(\d{2})\s*R\s*(\d{2})(?:\s*[A-Z])?/i);
    if (!sizeMatch) continue;

    const width = sizeMatch[1];
    const profile = sizeMatch[2];
    const rim = sizeMatch[3];

    rest = normalizeWhitespace(rest.replace(sizeMatch[0], ''));
    rest = rest.replace(/^[\-\.\s]+/, '');
    rest = rest.replace(/[\-\.\s]+$/, '');

    const tokens = rest.split(' ').filter(Boolean);
    if (tokens.length === 0) continue;

    const brand = normalizeBrand(tokens[0]);
    const model = normalizeWhitespace(tokens.slice(1).join(' '));
    const name = normalizeWhitespace(`Pneu ${width}/${profile} R${rim} ${brand} ${model}`.trim());

    parsed.push({
      qty,
      width,
      profile,
      rim,
      brand,
      model,
      name,
    });
  }

  // Consolida itens iguais para evitar duplicidade no cadastro.
  const grouped = new Map();
  for (const item of parsed) {
    const key = [item.width, item.profile, item.rim, item.brand.toLowerCase(), item.model.toLowerCase()].join('|');
    if (!grouped.has(key)) {
      grouped.set(key, { ...item });
    } else {
      grouped.get(key).qty += item.qty;
    }
  }

  return {
    items: Array.from(grouped.values()),
    location: defaultLocation || '',
  };
}

async function tryDirectOperationalCommand(userMessage) {
  const text = String(userMessage || '').trim();
  const lower = text.toLowerCase();

  const asksPreSaleCostUpdate =
    lower.includes('pré-venda') ||
    lower.includes('pre-venda') ||
    lower.includes('orcamento') ||
    lower.includes('orçamento');
  if (asksPreSaleCostUpdate) {
    return 'Para processar pré-venda e atualizar custo real do estoque, envie a imagem/documento da pré-venda aqui no Telegram (com ou sem legenda).';
  }

  const fillLocationIntent =
    (lower.includes('sem local') || lower.includes('sem localização') || lower.includes('sem localizacao')) &&
    (lower.includes('loja') || lower.includes('depósito') || lower.includes('deposito')) &&
    (lower.includes('pneu') || lower.includes('pneus'));
  if (fillLocationIntent) {
    const locationMatch = text.match(/\b(loja\s*\d+|dep[oó]sito)\b/i);
    const location = locationMatch ? locationMatch[1].trim() : '';
    if (!location) {
      return 'Informe o local no texto. Exemplo: "coloque todos os pneus sem local na Loja 02".';
    }
    const result = await runTool('fill_missing_product_location', {
      location,
      category: 'pneu',
    });
    return `Atualizacao concluida. ${result.updated_count ?? 0} pneu(s) sem local foram atualizados para ${location}.`;
  }

  if (lower.startsWith('cadastrar produto:')) {
    const payloadText = text.slice(text.indexOf(':') + 1).trim();
    const data = parseKeyValuePairs(payloadText);
    const categoryRaw = String(data.categoria || data.category || 'pneu').toLowerCase();
    const category = categoryRaw.includes('roda') ? 'roda' : 'pneu';
    const conditionRaw = String(data.estado || data.condicao || data.item_condition || 'novo').toLowerCase();
    const itemCondition = conditionRaw.includes('usad') ? 'usado' : 'novo';
    const usedConditionRaw = String(data.estado_usado || data.classificacao_usado || data.used_tire_condition || '').toLowerCase();
    let usedTireCondition = '';
    if (usedConditionRaw) {
      if (usedConditionRaw.includes('reparo')) usedTireCondition = 'seminovo_com_reparo';
      else if (usedConditionRaw.includes('meia')) usedTireCondition = 'meia_vida';
      else if (usedConditionRaw.includes('50') || usedConditionRaw.includes('twi')) usedTireCondition = 'abaixo_50_twi';
      else if (usedConditionRaw.includes('semi')) usedTireCondition = 'seminovo';
    }
    const rim = String(data.aro || data.rim || '').trim();
    const width = String(data.largura || data.width || '').trim();
    const profile = String(data.perfil || data.profile || '').trim();

    const result = await runTool('create_product', {
      name: data.nome || data.name || '',
      category,
      item_condition: itemCondition,
      used_tire_condition: usedTireCondition,
      brand: data.marca || data.brand || '',
      model: data.modelo || data.model || '',
      width,
      profile,
      rim,
      location: data.local || data.location || '',
      cost_price: Number((data.custo || data.cost_price || '0').replace(',', '.')),
      price: Number((data.preco || data.preço || data.venda || data.price || '0').replace(',', '.')),
      stock_qty: Number(data.estoque || data.stock_qty || 0),
    });
    return `Produto cadastrado com sucesso. ID do produto: ${result.product_id}.`;
  }

  // Cadastro em lote por texto livre de pneus.
  // Pode colar linhas como "4 pneus 205/45 R17 Lanvigator" e uma linha de localizacao.
  const hasManyPneuLines = text.split(/\r?\n/).filter((l) => /pne\w*/i.test(l)).length >= 5;
  const isBatchIntent = lower.startsWith('cadastrar lote pneus:') || hasManyPneuLines;
  if (isBatchIntent) {
    const payloadText = lower.startsWith('cadastrar lote pneus:') ? text.slice(text.indexOf(':') + 1) : text;
    const batch = parseTireBatch(payloadText);
    if (batch.items.length === 0) {
      return 'Nao consegui identificar os itens do lote. Use linhas no formato: 4 pneus 205/45 R17 Marca Modelo';
    }

    const created = [];
    const errors = [];
    for (const item of batch.items) {
      try {
        const result = await runTool('create_product', {
          name: item.name,
          category: 'pneu',
          item_condition: 'novo',
          brand: item.brand,
          model: item.model,
          width: item.width,
          profile: item.profile,
          rim: item.rim,
          location: batch.location,
          cost_price: 0,
          price: 0,
          stock_qty: item.qty,
        });
        created.push({ ...item, product_id: result.product_id });
      } catch (e) {
        errors.push(`${item.name} (qtd ${item.qty}): ${e.message}`);
      }
    }

    const totalQty = created.reduce((sum, it) => sum + it.qty, 0);
    let response = `Lote processado: ${created.length} item(ns) cadastrado(s), total ${totalQty} pneu(s).`;
    if (batch.location) {
      response += ` Local aplicado: ${batch.location}.`;
    }
    if (errors.length > 0) {
      response += ` Falhas: ${errors.length}. Exemplo: ${errors[0]}`;
    }
    return response;
  }

  if (lower.startsWith('ajustar estoque:')) {
    const payloadText = text.slice(text.indexOf(':') + 1).trim();
    const data = parseKeyValuePairs(payloadText);
    const movementPt = String(data.tipo || data.movement_type || '').toLowerCase();
    const movementType = movementPt === 'entrada' ? 'in' : movementPt === 'saida' || movementPt === 'saída' ? 'out' : movementPt;
    const result = await runTool('adjust_stock', {
      product_id: Number(data.produto_id || data.product_id || 0),
      movement_type: movementType,
      quantity: Number(data.quantidade || data.quantity || 0),
      note: data.obs || data.nota || data.note || 'Ajuste via agente',
    });
    return `Estoque ajustado com sucesso. Produto ${result.product_id} agora com estoque ${result.new_stock}.`;
  }

  if (lower.startsWith('realizar venda:') || lower.startsWith('pdv:')) {
    const payloadText = text.slice(text.indexOf(':') + 1).trim();
    const data = parseKeyValuePairs(payloadText);
    const items = parseSaleItems(data.itens || data.items || '');
    if (items.length === 0) {
      return 'Para realizar venda, informe itens no formato itens=1x2@499.9;3x1@120';
    }

    const result = await runTool('create_sale', {
      customer_name: data.cliente || data.customer_name || '',
      customer_phone: data.telefone || data.customer_phone || '',
      customer_tax_id: data.cpf || data.cnpj || data.customer_tax_id || '',
      payment_method: data.pagamento || data.payment_method || 'dinheiro',
      payment_status: data.status || data.payment_status || 'paid',
      due_date: data.vencimento || data.due_date || '',
      items,
    });
    return `Venda realizada com sucesso. Numero da venda: #${result.sale_id}.`;
  }

  // Venda a base de troca:
  // venda troca: cliente=..., itens=12x2@499.9;3x1@120, troca=20x2;21x1
  if (lower.startsWith('venda troca:') || lower.startsWith('venda base de troca:') || lower.startsWith('pdv troca:')) {
    const payloadText = text.slice(text.indexOf(':') + 1).trim();
    const data = parseKeyValuePairs(payloadText);

    const soldItems = parseSaleItems(data.itens || data.items || '');
    if (soldItems.length === 0) {
      return 'Para venda base de troca, informe itens vendidos no formato itens=12x2@499.9;3x1@120';
    }

    const exchangeItems = parseStockItems(data.troca || data.entrada_troca || data.itens_troca || '');
    if (exchangeItems.length === 0) {
      return 'Para venda base de troca, informe os itens de entrada no formato troca=20x2#pneu_usado;21x1#roda_usada';
    }

    const exchangeNote = String(data.obs_troca || data.observacao_troca || data.observacao || data.obs || '').trim();

    const saleResult = await runTool('create_sale', {
      customer_name: data.cliente || data.customer_name || '',
      customer_phone: data.telefone || data.customer_phone || '',
      customer_tax_id: data.cpf || data.cnpj || data.customer_tax_id || '',
      payment_method: data.pagamento || data.payment_method || 'dinheiro',
      payment_status: data.status || data.payment_status || 'paid',
      due_date: data.vencimento || data.due_date || '',
      items: soldItems,
    });

    const stockAdjustments = [];
    for (const item of exchangeItems) {
      const categoryLabel = item.category ? ` [categoria: ${item.category}]` : '';
      const noteParts = [
        `Entrada base de troca vinculada a venda #${saleResult.sale_id}${categoryLabel}`,
        exchangeNote ? `Obs: ${exchangeNote}` : '',
      ].filter(Boolean);

      const adj = await runTool('adjust_stock', {
        product_id: item.product_id,
        movement_type: 'in',
        quantity: item.quantity,
        note: noteParts.join(' | '),
      });
      stockAdjustments.push(adj);
    }

    return `Venda base de troca realizada com sucesso. Venda #${saleResult.sale_id} criada e ${stockAdjustments.length} entrada(s) de estoque registradas.`;
  }

  return null;
}

async function tryDirectBusinessAnswer(userMessage) {
  const text = String(userMessage || '').toLowerCase();
  const asksSales = /(venda|vendas|faturamento|faturou|vendemos)/i.test(text);
  if (!asksSales) {
    return null;
  }

  const period = parsePeriodFromMessage(userMessage);
  if (period) {
    const result = await runTool('get_sales_total_for_period', {
      date_from: period.dateFrom,
      date_to: period.dateTo,
    });
    return `${period.label}, vendemos ${moneyBr(result.total_amount)} em ${result.sales_count} venda(s).`;
  }

  return null;
}

async function buildBusinessContext(userMessage) {
  const text = String(userMessage || '').toLowerCase();
  const asksSales = /(venda|vendas|faturamento|faturou|vendemos)/i.test(text);

  if (!asksSales) {
    return '';
  }

  if (/\bontem\b/i.test(text)) {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    const date = toYmd(d);
    const result = await runTool('get_sales_total_for_date', { date });
    return `Faturamento em ${date}: R$ ${Number(result.total_amount).toFixed(2)} (${result.sales_count} venda(s)).`;
  }

  if (/\bhoje\b/i.test(text)) {
    const date = toYmd(new Date());
    const result = await runTool('get_sales_total_for_date', { date });
    return `Faturamento em ${date}: R$ ${Number(result.total_amount).toFixed(2)} (${result.sales_count} venda(s)).`;
  }

  return '';
}

async function generateOpenAIReply(userMessage) {
  if (!userMessage) {
    return 'Nao entendi a mensagem.';
  }

  const tools = listTools();
  const businessContext = await buildBusinessContext(userMessage);

  const response = await openai.responses.create({
    model: config.openaiModel,
    input: [
      {
        role: 'system',
        content: [
          {
            type: 'input_text',
            text: 'You are a concise assistant integrated with Telegram for an ERP. Reply in Brazilian Portuguese. Use available tools for business numbers whenever relevant and do not claim lack of access if tool data is provided.',
          },
        ],
      },
      ...(businessContext
        ? [{
            role: 'system',
            content: [{ type: 'input_text', text: `Business context: ${businessContext}` }],
          }]
        : []),
      {
        role: 'user',
        content: [{ type: 'input_text', text: userMessage }],
      },
    ],
    tools: tools.map((tool) => ({
      type: 'function',
      name: tool.name,
      description: tool.description,
      parameters: tool.input_schema,
    })),
  });

  const functionCall = response.output?.find((item) => item.type === 'function_call');

  if (functionCall) {
    const args = functionCall.arguments ? JSON.parse(functionCall.arguments) : {};
    const toolResult = await runTool(functionCall.name, args);

    const followup = await openai.responses.create({
      model: config.openaiModel,
      input: [
        ...response.output,
        {
          type: 'function_call_output',
          call_id: functionCall.call_id,
          output: JSON.stringify(toolResult),
        },
      ],
    });

    return followup.output_text?.trim() || 'Concluido.';
  }

  return response.output_text?.trim() || 'Sem resposta.';
}

export async function generateAgentReply(userMessage) {
  const directOperational = await tryDirectOperationalCommand(userMessage);
  if (directOperational) {
    return directOperational;
  }

  const directAnswer = await tryDirectBusinessAnswer(userMessage);
  if (directAnswer) {
    return directAnswer;
  }

  const provider = config.llmProvider.toLowerCase();
  const businessContext = await buildBusinessContext(userMessage);

  if (provider === 'ollama') {
    return generateOllamaReply(userMessage, businessContext);
  }

  if (provider === 'openai') {
    return generateOpenAIReply(userMessage);
  }

  // auto: tenta OpenAI primeiro, cai para Ollama em caso de erro/quota.
  try {
    return await generateOpenAIReply(userMessage);
  } catch (openaiError) {
    console.warn('[agent] OpenAI unavailable, switching to Ollama fallback:', openaiError?.code || openaiError?.message);
    try {
      return await generateOllamaReply(userMessage, businessContext);
    } catch (ollamaError) {
      const combinedError = new Error('No LLM provider available (OpenAI and Ollama failed).');
      combinedError.code = 'no_llm_available';
      combinedError.openai = {
        code: openaiError?.code || null,
        status: openaiError?.status || null,
        message: openaiError?.message || null,
      };
      combinedError.ollama = {
        code: ollamaError?.code || null,
        status: ollamaError?.status || null,
        message: ollamaError?.message || null,
      };
      throw combinedError;
    }
  }
}
