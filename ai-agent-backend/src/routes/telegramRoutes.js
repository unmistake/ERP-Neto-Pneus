import { Router } from 'express';
import { verifyTelegramSecret } from '../middlewares/verifyTelegramSecret.js';
import { generateAgentReply } from '../services/agentService.js';
import { sendTelegramMessage, setTelegramWebhook, getTelegramFileBuffer } from '../services/telegramService.js';
import { processPreSaleImageAndUpdateCosts } from '../services/preSaleService.js';

const router = Router();

function extractUpdatePayload(update) {
  const messageObj = update.message || update.edited_message || update.channel_post || update.edited_channel_post || update.callback_query?.message;
  const chatId = messageObj?.chat?.id || update.callback_query?.from?.id || null;
  const photoList = update.message?.photo || update.channel_post?.photo || [];
  // Evita sempre usar a maior imagem (pode estourar o modelo de visao local).
  const bestPhoto = Array.isArray(photoList) && photoList.length > 0
    ? photoList[Math.max(0, photoList.length - 2)]
    : null;
  const doc = update.message?.document || update.channel_post?.document || null;
  const text = update.message?.text
    || update.edited_message?.text
    || update.channel_post?.text
    || update.edited_channel_post?.text
    || update.callback_query?.data
    || update.message?.caption
    || update.channel_post?.caption
    || '';

  return {
    chatId,
    text: String(text || '').trim(),
    imageFileId: bestPhoto?.file_id || null,
    documentFileId: doc?.file_id || null,
    documentMimeType: doc?.mime_type || '',
  };
}

router.post('/webhooks/telegram', verifyTelegramSecret, async (req, res) => {
  const update = req.body || {};
  const { chatId, text, imageFileId, documentFileId, documentMimeType } = extractUpdatePayload(update);

  try {
    if (!chatId) {
      return res.status(200).json({ ok: true, ignored: true });
    }

    if (imageFileId || documentFileId) {
      try {
        const fileId = imageFileId || documentFileId;
        const mimeType = imageFileId ? 'image/jpeg' : (documentMimeType || 'image/jpeg');
        if (!imageFileId && !mimeType.toLowerCase().startsWith('image/')) {
          await sendTelegramMessage(chatId, 'Envie o arquivo como imagem (JPG/PNG). PDF e outros formatos ainda nao sao suportados neste fluxo.');
          return res.status(200).json({ ok: true });
        }
        const imageBuffer = await getTelegramFileBuffer(fileId);
        const result = await processPreSaleImageAndUpdateCosts(imageBuffer, mimeType);
        const msg = [
          'Pré-venda processada com sucesso.',
          `Itens extraídos: ${result.extracted_count}`,
          `Custos atualizados: ${result.updated_count}`,
          `Ignorados (já tinham custo): ${result.skipped_already_filled_count}`,
          `Sem match no estoque: ${result.not_found_count}`,
        ].join('\n');
        await sendTelegramMessage(chatId, msg);
      } catch (error) {
        console.error('[pre-sale image processing error]', error);
        const code = error?.code || '';
        let msg = 'Nao consegui processar esta imagem de pre-venda.';
        if (code === 'vision_provider_not_configured') {
          msg += ' Configure OLLAMA_VISION_MODEL (ou OPENAI com cota) e tente novamente.';
        } else if (code === 'vision_ollama_http_error' || code === 'vision_ollama_parse_error') {
          msg += ' O modelo de visao do Ollama falhou. Verifique OLLAMA_VISION_MODEL.';
        } else if (code === 'pre_sale_no_items_extracted') {
          msg += ' Nao consegui extrair itens de pneus deste documento.';
        } else {
          msg += ` Detalhe: ${error?.message || 'erro interno'}`;
        }
        await sendTelegramMessage(chatId, msg);
      }
      return res.status(200).json({ ok: true });
    }

    if (!text) {
      return res.status(200).json({ ok: true, ignored: true });
    }

    const reply = await generateAgentReply(text);
    await sendTelegramMessage(chatId, reply);

    return res.status(200).json({ ok: true });
  } catch (error) {
    console.error('[telegram webhook error]', error);
    try {
      const status = error?.status;
      const code = error?.code;
      const type = error?.type;

      if (!chatId) {
        return res.status(200).json({ ok: true, ignored: true, reason: 'chat_not_found' });
      }

      if (status === 429 || code === 'insufficient_quota' || type === 'insufficient_quota') {
        await sendTelegramMessage(
          chatId,
          'Estou temporariamente sem cota de IA no momento. Tente novamente em alguns minutos.'
        );
      } else if (code === 'no_llm_available' || code === 'ollama_http_error') {
        console.warn('[telegram llm unavailable]', { openai: error?.openai, ollama: error?.ollama, message: error?.message });
        await sendTelegramMessage(
          chatId,
          'No momento estou sem acesso ao provedor de IA. Verifique se o Ollama esta ativo e se o modelo configurado foi baixado.'
        );
      } else {
        await sendTelegramMessage(
          chatId,
          'Tive um erro temporario ao processar sua mensagem. Tente novamente em instantes.'
        );
      }
    } catch (fallbackError) {
      console.error('[telegram fallback error]', fallbackError);
    }
    // Sempre responder 200 para o Telegram nao ficar reenviando a mesma mensagem.
    return res.status(200).json({ ok: true, handled_with_error: true });
  }
});

router.post('/telegram/set-webhook', async (_req, res) => {
  try {
    const response = await setTelegramWebhook();
    return res.status(200).json({ ok: true, data: response });
  } catch (error) {
    console.error('[set webhook error]', error);
    return res.status(500).json({ ok: false, error: error.message });
  }
});

export default router;
