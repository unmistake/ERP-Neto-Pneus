import express from 'express';
import healthRoutes from './routes/healthRoutes.js';
import telegramRoutes from './routes/telegramRoutes.js';
import agentRoutes from './routes/agentRoutes.js';
import llmRoutes from './routes/llmRoutes.js';

const app = express();

app.use(express.json({ limit: '1mb' }));

app.use(healthRoutes);
app.use(agentRoutes);
app.use(llmRoutes);
app.use(telegramRoutes);

app.use((req, res) => {
  res.status(404).json({ ok: false, error: `Route not found: ${req.method} ${req.path}` });
});

app.use((err, _req, res, _next) => {
  console.error('[unhandled error]', err);
  res.status(500).json({ ok: false, error: 'Unexpected server error' });
});

export default app;
