import { config } from '../config/env.js';

export function verifyTelegramSecret(req, res, next) {
  if (!config.telegramWebhookSecret) {
    return next();
  }

  const incomingSecret = req.headers['x-telegram-bot-api-secret-token'];
  if (incomingSecret !== config.telegramWebhookSecret) {
    return res.status(401).json({ ok: false, error: 'Invalid Telegram secret' });
  }

  return next();
}
