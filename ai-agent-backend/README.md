# AI Agent Backend (Telegram + LLM)

## Stack
- Node.js + Express
- Telegram Bot API (Webhook)
- OpenAI API (opcional)
- Ollama local (opcional e gratuito)

## Estrutura
- `src/server.js` bootstrap do servidor
- `src/app.js` app Express
- `src/routes/` rotas HTTP
- `src/services/` integracoes e regras de negocio
- `src/tools/` ferramentas acionaveis pelo agente
- `src/config/` variaveis e constantes
- `src/middlewares/` middlewares reutilizaveis

## Setup
1. Copie `.env.example` para `.env`
2. Preencha `TELEGRAM_BOT_TOKEN` e `BASE_URL`
   - Para dados reais do ERP, configure tambem `ERP_API_BASE_URL` e `ERP_API_TOKEN`
3. Escolha o provedor em `LLM_PROVIDER`:
   - `ollama` (sem custo)
   - `openai`
   - `auto` (tenta OpenAI e cai para Ollama)
4. Instale dependencias:
   - `npm install`
5. Rode:
   - `npm run dev`

## Ollama (sem custo)
1. Instale Ollama
2. Baixe um modelo:
   - `ollama pull llama3.1:8b`
3. Deixe o Ollama ativo (porta padrao `11434`)
4. Configure no `.env`:
   - `LLM_PROVIDER=ollama`
   - `OLLAMA_BASE_URL=http://127.0.0.1:11434`
   - `OLLAMA_MODEL=llama3.1:8b`

## Endpoints
- `GET /health`
- `GET /llm/status`
- `GET /llm/status?check=1`
- `POST /agent/reply`
- `POST /webhooks/telegram`
- `POST /telegram/set-webhook`

`/llm/status`:
- Sem `check`: mostra apenas configuracao atual.
- Com `check=1`: testa conectividade OpenAI/Ollama em tempo real.

## Dados de negocio no agente
- O agente consulta a API do ERP para responder com numeros reais.
- Exemplo implementado: faturamento de hoje/ontem.
- Variaveis no `.env`:
  - `ERP_API_BASE_URL` (ex.: `http://localhost/ERP/api`)
  - `ERP_API_TOKEN` (mesmo token definido no ERP em `config/api.php`)

## Comandos operacionais via mensagem (Telegram)
- Cadastrar produto:
  - Pneu: `cadastrar produto: nome=Pneu X, categoria=pneu, estado=novo, marca=Michelin, largura=205, perfil=55, aro=16, local=A1, custo=350, venda=499.9, estoque=10`
  - Roda: `cadastrar produto: nome=Roda Y, categoria=roda, estado=usado, marca=Scorro, aro=17, local=B2, custo=220, venda=350, estoque=4`
- Ajustar estoque:
  - `ajustar estoque: produto_id=12, tipo=entrada, quantidade=5, obs=Reposicao`
  - `ajustar estoque: produto_id=12, tipo=saida, quantidade=2, obs=Perda`
- Realizar venda (PDV):
  - `realizar venda: cliente=Joao Silva, telefone=11 99999-0000, cpf=123.456.789-00, pagamento=pix, status=paid, itens=12x2@499.9;3x1@120`
- Realizar venda base de troca (saida + entrada):
  - `venda troca: cliente=Joao Silva, pagamento=pix, status=paid, itens=12x2@499.9;3x1@120, troca=20x2#pneu_usado;21x1#roda_usada, obs_troca=carcaca em bom estado`

Formato de itens:
- `produto_id x quantidade @ preco_unitario`
- Separar itens com `;`

Formato de troca:
- `produto_id x quantidade #categoria` (categoria opcional)
- Separar itens com `;`

Campos extras na venda troca:
- `obs_troca` (opcional): observacao geral da troca, gravada nas movimentacoes de entrada.
