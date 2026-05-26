import { Router } from 'express';
import { generateAgentReply } from '../services/agentService.js';

const router = Router();

router.post('/agent/reply', async (req, res) => {
  try {
    const message = String(req.body?.message || '').trim();
    if (!message) {
      return res.status(422).json({ ok: false, error: 'Campo message e obrigatorio.' });
    }

    const reply = await generateAgentReply(message);
    return res.status(200).json({ ok: true, data: { reply } });
  } catch (error) {
    console.error('[agent reply error]', error);
    return res.status(500).json({ ok: false, error: 'Erro ao gerar resposta do agente.' });
  }
});

export default router;
