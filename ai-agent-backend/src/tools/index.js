import { erpGet, erpPost } from '../services/erpApiService.js';

export function listTools() {
  return [
    {
      name: 'get_datetime',
      description: 'Returns current server date and time in ISO format.',
      input_schema: {
        type: 'object',
        properties: {},
        additionalProperties: false,
      },
    },
    {
      name: 'get_sales_total_for_date',
      description: 'Retorna faturamento total de vendas para uma data no formato YYYY-MM-DD.',
      input_schema: {
        type: 'object',
        properties: {
          date: { type: 'string', description: 'Data no formato YYYY-MM-DD' },
        },
        required: ['date'],
        additionalProperties: false,
      },
    },
    {
      name: 'get_sales_total_for_period',
      description: 'Retorna faturamento total de vendas para um periodo YYYY-MM-DD ate YYYY-MM-DD.',
      input_schema: {
        type: 'object',
        properties: {
          date_from: { type: 'string', description: 'Data inicial no formato YYYY-MM-DD' },
          date_to: { type: 'string', description: 'Data final no formato YYYY-MM-DD' },
        },
        required: ['date_from', 'date_to'],
        additionalProperties: false,
      },
    },
    {
      name: 'create_product',
      description: 'Cadastra um produto no estoque.',
      input_schema: {
        type: 'object',
        properties: {
          name: { type: 'string' },
          category: { type: 'string', enum: ['pneu', 'roda'] },
          item_condition: { type: 'string', enum: ['novo', 'usado'] },
          used_tire_condition: { type: 'string', enum: ['seminovo', 'meia_vida', 'abaixo_50_twi', 'seminovo_com_reparo'] },
          brand: { type: 'string' },
          model: { type: 'string' },
          width: { type: 'string' },
          profile: { type: 'string' },
          rim: { type: 'string' },
          location: { type: 'string' },
          cost_price: { type: 'number' },
          price: { type: 'number' },
          stock_qty: { type: 'integer' },
        },
        required: ['name', 'category', 'item_condition', 'cost_price', 'stock_qty'],
        additionalProperties: false,
      },
    },
    {
      name: 'adjust_stock',
      description: 'Ajusta estoque de um produto por ID.',
      input_schema: {
        type: 'object',
        properties: {
          product_id: { type: 'integer' },
          movement_type: { type: 'string', enum: ['in', 'out'] },
          quantity: { type: 'integer' },
          note: { type: 'string' },
        },
        required: ['product_id', 'movement_type', 'quantity'],
        additionalProperties: false,
      },
    },
    {
      name: 'fill_missing_product_location',
      description: 'Preenche local para produtos sem local cadastrado.',
      input_schema: {
        type: 'object',
        properties: {
          location: { type: 'string' },
          category: { type: 'string', enum: ['pneu', 'roda'] },
        },
        required: ['location'],
        additionalProperties: false,
      },
    },
    {
      name: 'create_sale',
      description: 'Realiza uma venda no PDV.',
      input_schema: {
        type: 'object',
        properties: {
          customer_name: { type: 'string' },
          customer_phone: { type: 'string' },
          customer_tax_id: { type: 'string' },
          payment_method: { type: 'string' },
          payment_status: { type: 'string', enum: ['paid', 'pending'] },
          due_date: { type: 'string' },
          items: {
            type: 'array',
            items: {
              type: 'object',
              properties: {
                product_id: { type: 'integer' },
                quantity: { type: 'integer' },
                unit_price: { type: 'number' },
              },
              required: ['product_id', 'quantity', 'unit_price'],
              additionalProperties: false,
            },
          },
        },
        required: ['items'],
        additionalProperties: false,
      },
    },
  ];
}

export async function runTool(name, args) {
  if (name === 'get_datetime') {
    return { now: new Date().toISOString() };
  }

  if (name === 'get_sales_total_for_date') {
    const date = String(args?.date || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      throw new Error('Data invalida para get_sales_total_for_date. Use YYYY-MM-DD.');
    }

    const response = await erpGet('sales', {
      page: 1,
      limit: 200,
      date_from: date,
      date_to: date,
    });

    const sales = Array.isArray(response?.data) ? response.data : [];
    const total = sales.reduce((sum, item) => sum + Number(item.total_amount || 0), 0);

    return {
      date,
      sales_count: sales.length,
      total_amount: Number(total.toFixed(2)),
    };
  }

  if (name === 'get_sales_total_for_period') {
    const dateFrom = String(args?.date_from || '').trim();
    const dateTo = String(args?.date_to || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateFrom) || !/^\d{4}-\d{2}-\d{2}$/.test(dateTo)) {
      throw new Error('Datas invalidas para get_sales_total_for_period. Use YYYY-MM-DD.');
    }

    let page = 1;
    const limit = 200;
    let hasNext = true;
    let salesCount = 0;
    let total = 0;

    while (hasNext) {
      const response = await erpGet('sales', {
        page,
        limit,
        date_from: dateFrom,
        date_to: dateTo,
      });

      const sales = Array.isArray(response?.data) ? response.data : [];
      salesCount += sales.length;
      total += sales.reduce((sum, item) => sum + Number(item.total_amount || 0), 0);

      hasNext = Boolean(response?.pagination?.has_next);
      page += 1;
    }

    return {
      date_from: dateFrom,
      date_to: dateTo,
      sales_count: salesCount,
      total_amount: Number(total.toFixed(2)),
    };
  }

  if (name === 'create_product') {
    const category = String(args?.category || 'pneu').trim();
    const itemCondition = String(args?.item_condition || 'novo').trim();
    const rim = String(args?.rim || '').trim();

    const payload = {
      name: String(args?.name || '').trim(),
      category,
      item_condition: itemCondition,
      used_tire_condition: String(args?.used_tire_condition || '').trim(),
      brand: String(args?.brand || '').trim(),
      model: String(args?.model || '').trim(),
      width: String(args?.width || '').trim(),
      profile: String(args?.profile || '').trim(),
      rim,
      location: String(args?.location || '').trim(),
      cost_price: Number(args?.cost_price ?? 0),
      price: Number(args?.price ?? 0),
      stock_qty: Number(args?.stock_qty ?? 0),
    };

    if (!['pneu', 'roda'].includes(category)) {
      throw new Error('Categoria invalida. Use pneu ou roda.');
    }
    if (!['novo', 'usado'].includes(itemCondition)) {
      throw new Error('Estado invalido. Use novo ou usado.');
    }
    if (category === 'pneu' && (!payload.width || !payload.profile || !rim)) {
      throw new Error('Para pneu, informe largura, perfil e aro.');
    }
    if (category === 'roda' && !rim) {
      throw new Error('Para roda, informe aro.');
    }
    if (category === 'pneu' && itemCondition === 'usado' && !['seminovo', 'meia_vida', 'abaixo_50_twi', 'seminovo_com_reparo'].includes(payload.used_tire_condition)) {
      throw new Error('Para pneu usado, informe used_tire_condition valido.');
    }

    const response = await erpPost('products', payload);
    return { created: true, product_id: response?.data?.id ?? null };
  }

  if (name === 'adjust_stock') {
    const payload = {
      product_id: Number(args?.product_id ?? 0),
      movement_type: String(args?.movement_type || '').trim(),
      quantity: Number(args?.quantity ?? 0),
      note: String(args?.note || '').trim(),
    };
    const response = await erpPost('stock-adjustments', payload);
    return { adjusted: true, ...response?.data };
  }

  if (name === 'fill_missing_product_location') {
    const payload = {
      location: String(args?.location || '').trim(),
      category: String(args?.category || '').trim(),
    };
    if (!payload.location) {
      throw new Error('Informe o local para preenchimento.');
    }
    if (payload.category && !['pneu', 'roda'].includes(payload.category)) {
      throw new Error('Categoria invalida. Use pneu ou roda.');
    }
    const response = await erpPost('products/fill-location', payload);
    return { updated: true, ...response?.data };
  }

  if (name === 'create_sale') {
    const payload = {
      customer_name: String(args?.customer_name || '').trim(),
      customer_phone: String(args?.customer_phone || '').trim(),
      customer_tax_id: String(args?.customer_tax_id || '').trim(),
      payment_method: String(args?.payment_method || 'dinheiro').trim(),
      payment_status: String(args?.payment_status || 'paid').trim(),
      due_date: String(args?.due_date || '').trim(),
      items: Array.isArray(args?.items) ? args.items.map((it) => ({
        product_id: Number(it?.product_id ?? 0),
        quantity: Number(it?.quantity ?? 0),
        unit_price: Number(it?.unit_price ?? 0),
      })) : [],
    };
    const response = await erpPost('sales', payload);
    return { created: true, sale_id: response?.data?.sale_id ?? null };
  }

  throw new Error(`Tool not found: ${name}`);
}
