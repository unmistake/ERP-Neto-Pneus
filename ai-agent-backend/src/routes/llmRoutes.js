import { Router } from 'express';
import { config } from '../config/env.js';
import { openai } from '../services/openaiClient.js';

const router = Router();

async function checkOpenAI() {
  if (!config.openaiApiKey) {
    return { configured: false, available: false, detail: 'OPENAI_API_KEY nao configurada' };
  }

  try {
    await openai.models.list({ limit: 1 });
    return { configured: true, available: true, detail: 'ok' };
  } catch (error) {
    return {
      configured: true,
      available: false,
      detail: error?.message || 'erro ao consultar OpenAI',
      code: error?.code || null,
      status: error?.status || null,
    };
  }
}

async function checkOllama() {
  try {
    const response = await fetch(`${config.ollamaBaseUrl}/api/tags`);
    if (!response.ok) {
      return { configured: true, available: false, detail: `HTTP ${response.status}` };
    }

    const body = await response.json();
    const modelNames = (body.models || []).map((m) => m.name);
    const hasModel = modelNames.includes(config.ollamaModel);

    return {
      configured: true,
      available: hasModel,
      detail: hasModel ? 'ok' : `modelo ${config.ollamaModel} nao encontrado`,
      models: modelNames,
    };
  } catch (error) {
    return {
      configured: true,
      available: false,
      detail: error?.message || 'erro ao consultar Ollama',
    };
  }
}

router.get('/llm/status', async (req, res) => {
  const check = String(req.query?.check || '0') === '1';

  const base = {
    ok: true,
    data: {
      provider: config.llmProvider,
      openai: {
        configured: Boolean(config.openaiApiKey),
        model: config.openaiModel,
      },
      ollama: {
        configured: true,
        base_url: config.ollamaBaseUrl,
        model: config.ollamaModel,
      },
    },
  };

  if (!check) {
    return res.status(200).json(base);
  }

  const [openaiStatus, ollamaStatus] = await Promise.all([checkOpenAI(), checkOllama()]);
  return res.status(200).json({
    ...base,
    data: {
      ...base.data,
      openai: { ...base.data.openai, ...openaiStatus },
      ollama: { ...base.data.ollama, ...ollamaStatus },
    },
  });
});

export default router;
