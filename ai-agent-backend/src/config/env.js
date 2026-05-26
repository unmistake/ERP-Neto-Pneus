import dotenv from 'dotenv';

dotenv.config();

const required = ['TELEGRAM_BOT_TOKEN'];

for (const key of required) {
  if (!process.env[key]) {
    console.warn(`[config] Missing env var: ${key}`);
  }
}

export const config = {
  nodeEnv: process.env.NODE_ENV || 'development',
  port: Number(process.env.PORT || 3001),
  baseUrl: process.env.BASE_URL || '',
  telegramBotToken: process.env.TELEGRAM_BOT_TOKEN || '',
  telegramWebhookSecret: process.env.TELEGRAM_WEBHOOK_SECRET || '',
  openaiApiKey: process.env.OPENAI_API_KEY || '',
  openaiModel: process.env.OPENAI_MODEL || 'gpt-4.1-mini',
  llmProvider: process.env.LLM_PROVIDER || 'auto', // auto | openai | ollama
  ollamaBaseUrl: process.env.OLLAMA_BASE_URL || 'http://127.0.0.1:11434',
  ollamaModel: process.env.OLLAMA_MODEL || 'llama3.1:8b',
  ollamaVisionModel: process.env.OLLAMA_VISION_MODEL || '',
  erpApiBaseUrl: process.env.ERP_API_BASE_URL || 'http://localhost/ERP/api',
  erpApiToken: process.env.ERP_API_TOKEN || '',
};
