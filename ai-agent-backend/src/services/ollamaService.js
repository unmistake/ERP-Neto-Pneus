import { config } from '../config/env.js';

export async function generateOllamaReply(userMessage, businessContext = '') {
  const prompt = [
    'Voce e um assistente conciso integrado a um ERP e ao Telegram.',
    'Responda sempre em portugues do Brasil.',
    'Quando houver contexto de negocio, use os numeros fornecidos e nao diga que nao tem acesso.',
    businessContext ? `Contexto de negocio:\n${businessContext}` : '',
    `Usuario: ${userMessage}`,
  ].join('\n');

  const response = await fetch(`${config.ollamaBaseUrl}/api/generate`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: config.ollamaModel,
      prompt,
      stream: false,
    }),
  });

  if (!response.ok) {
    const body = await response.text();
    const error = new Error(`Ollama error: ${response.status} ${body}`);
    error.code = 'ollama_http_error';
    error.status = response.status;
    throw error;
  }

  const data = await response.json();
  return (data.response || '').trim() || 'Sem resposta.';
}
