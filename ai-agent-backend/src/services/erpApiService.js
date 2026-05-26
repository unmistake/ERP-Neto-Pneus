import { config } from '../config/env.js';

function buildHeaders() {
  const headers = { 'Content-Type': 'application/json' };
  if (config.erpApiToken) {
    headers.Authorization = `Bearer ${config.erpApiToken}`;
  }
  return headers;
}

export async function erpGet(path, query = {}) {
  if (!config.erpApiBaseUrl) {
    throw new Error('ERP_API_BASE_URL nao configurada.');
  }

  const url = new URL(path, config.erpApiBaseUrl.endsWith('/') ? config.erpApiBaseUrl : `${config.erpApiBaseUrl}/`);
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value));
    }
  });

  const response = await fetch(url.toString(), {
    method: 'GET',
    headers: buildHeaders(),
  });

  const text = await response.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = { raw: text };
  }

  if (!response.ok || body?.ok === false) {
    throw new Error(`ERP API GET ${path} falhou: ${response.status} ${body?.error || text}`);
  }

  return body;
}

export async function erpPost(path, payload = {}) {
  if (!config.erpApiBaseUrl) {
    throw new Error('ERP_API_BASE_URL nao configurada.');
  }

  const url = new URL(path, config.erpApiBaseUrl.endsWith('/') ? config.erpApiBaseUrl : `${config.erpApiBaseUrl}/`);
  const response = await fetch(url.toString(), {
    method: 'POST',
    headers: buildHeaders(),
    body: JSON.stringify(payload),
  });

  const text = await response.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = { raw: text };
  }

  if (!response.ok || body?.ok === false) {
    throw new Error(`ERP API POST ${path} falhou: ${response.status} ${body?.error || text}`);
  }

  return body;
}

export async function erpPatch(path, payload = {}) {
  if (!config.erpApiBaseUrl) {
    throw new Error('ERP_API_BASE_URL nao configurada.');
  }

  const url = new URL(path, config.erpApiBaseUrl.endsWith('/') ? config.erpApiBaseUrl : `${config.erpApiBaseUrl}/`);
  const response = await fetch(url.toString(), {
    method: 'PATCH',
    headers: buildHeaders(),
    body: JSON.stringify(payload),
  });

  const text = await response.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = { raw: text };
  }

  if (!response.ok || body?.ok === false) {
    throw new Error(`ERP API PATCH ${path} falhou: ${response.status} ${body?.error || text}`);
  }

  return body;
}
