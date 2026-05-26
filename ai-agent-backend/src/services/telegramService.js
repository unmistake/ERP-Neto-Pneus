import { config } from '../config/env.js';

const telegramApiBase = `https://api.telegram.org/bot${config.telegramBotToken}`;

export async function sendTelegramMessage(chatId, text) {
  const response = await fetch(`${telegramApiBase}/sendMessage`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      chat_id: chatId,
      text,
    }),
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`Telegram sendMessage failed: ${response.status} ${body}`);
  }

  return response.json();
}

export async function setTelegramWebhook() {
  if (!config.baseUrl) {
    throw new Error('BASE_URL is required to set webhook');
  }

  const response = await fetch(`${telegramApiBase}/setWebhook`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      url: `${config.baseUrl}/webhooks/telegram`,
      secret_token: config.telegramWebhookSecret || undefined,
    }),
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`Telegram setWebhook failed: ${response.status} ${body}`);
  }

  return response.json();
}

export async function getTelegramFileBuffer(fileId) {
  const fileInfoResponse = await fetch(`${telegramApiBase}/getFile`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ file_id: fileId }),
  });
  if (!fileInfoResponse.ok) {
    const body = await fileInfoResponse.text();
    throw new Error(`Telegram getFile failed: ${fileInfoResponse.status} ${body}`);
  }

  const fileInfo = await fileInfoResponse.json();
  const filePath = fileInfo?.result?.file_path;
  if (!filePath) {
    throw new Error('Telegram nao retornou file_path.');
  }

  const fileResponse = await fetch(`https://api.telegram.org/file/bot${config.telegramBotToken}/${filePath}`);
  if (!fileResponse.ok) {
    const body = await fileResponse.text();
    throw new Error(`Telegram file download failed: ${fileResponse.status} ${body}`);
  }

  const arrayBuffer = await fileResponse.arrayBuffer();
  return Buffer.from(arrayBuffer);
}
