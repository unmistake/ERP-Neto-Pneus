# ERP Loja de Pneus (PHP + Tailwind + MySQL)

## Requisitos
- XAMPP com Apache + MySQL
- PHP 8+
- Banco MySQL `ERP`

## Instalação
1. Copie a pasta `ERP` para `htdocs` do XAMPP.
2. Abra o phpMyAdmin (normalmente `http://localhost/phpmyadmin`).
3. Importe o arquivo `sql/schema.sql`.
4. Confirme as credenciais em `config/database.php` (padrao XAMPP: usuario `root`, senha vazia).
5. Acesse no navegador: `http://localhost/ERP/index.php`.

## Módulos
- Dashboard com resumo de produtos, estoque, vendas e financeiro.
- Estoque: cadastro de pneus e ajuste de entrada/saida.
- PDV rapido: lancamento de venda com varios itens, baixa de estoque e conta a receber automatica para vendas pendentes.
- Financeiro: contas a pagar e contas a receber.

## Estrutura
- `config/` conexao com banco
- `includes/` layout e funcoes utilitarias
- `pages/` telas do sistema
- `actions/` processamento de formularios
- `sql/` script de criacao do banco
- `api/` API JSON para integracoes (agente de IA)

## API para Agente de IA
### Configuracao
1. Edite `config/api.php` e troque o token padrao.
2. Envie o header `Authorization: Bearer SEU_TOKEN` em todas as chamadas.

Base URL local (REST):
- `http://localhost/ERP/api`

### Rotas
- `GET /health`
- `GET /products`
- `GET /customers`
- `POST /customers`
- `GET /customers/{id}`
- `GET /sales`
- `POST /sales`
- `GET /sales/{id}`
- `GET /costs`
- `POST /costs`
- `GET /costs/{id}`
- `PUT /costs/{id}`
- `PATCH /costs/{id}`
- `DELETE /costs/{id}`

Obs.: o formato antigo com `?resource=...` continua funcionando por compatibilidade.

### Paginacao e filtros
Todas as listagens aceitam:
- `page` (padrao: `1`)
- `limit` (padrao: `20`, max: `200`)

`GET /products` filtros:
- `q` (busca em nome/marca/modelo)
- `brand`
- `model`
- `stock_status` (`in_stock` ou `out_of_stock`)

`GET /customers` filtros:
- `q` (busca em nome/telefone/cpf-cnpj)
- `tax_id`
- `phone`

`GET /sales` filtros:
- `q` (nome cliente ou id da venda)
- `customer_id`
- `payment_status` (`paid` ou `pending`)
- `payment_method` (`dinheiro`, `pix`, `cartao`, `prazo`, etc.)
- `date_from` (YYYY-MM-DD)
- `date_to` (YYYY-MM-DD)

`GET /costs` filtros:
- `q` (descricao/categoria)
- `category`
- `date_from` (YYYY-MM-DD)
- `date_to` (YYYY-MM-DD)
- `amount_min`
- `amount_max`

Resposta de listagem inclui:
- `data`
- `pagination`: `page`, `limit`, `total`, `total_pages`, `has_next`, `has_prev`

### Exemplo: criar cliente
```bash
curl -X POST "http://localhost/ERP/api/customers" \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"first_name\":\"Joao\",\"last_name\":\"Silva\",\"phone\":\"11 99999-0000\",\"tax_id\":\"123.456.789-00\"}"
```

### Exemplo: criar venda
```bash
curl -X POST "http://localhost/ERP/api/sales" \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"customer_name\": \"Joao Silva\",
    \"customer_phone\": \"11 99999-0000\",
    \"customer_tax_id\": \"123.456.789-00\",
    \"payment_method\": \"pix\",
    \"payment_status\": \"paid\",
    \"items\": [
      {\"product_id\": 1, \"quantity\": 2, \"unit_price\": 499.9}
    ]
  }"
```

### Exemplo: detalhes de uma venda
```bash
curl -X GET "http://localhost/ERP/api/sales/123" \
  -H "Authorization: Bearer SEU_TOKEN"
```

### Exemplo: vendas paginadas e filtradas
```bash
curl -X GET "http://localhost/ERP/api/sales?page=1&limit=10&payment_status=pending&date_from=2026-01-01&date_to=2026-12-31" \
  -H "Authorization: Bearer SEU_TOKEN"
```

### Exemplo: criar custo
```bash
curl -X POST "http://localhost/ERP/api/costs" \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"description\":\"Frete\",\"category\":\"Logistica\",\"amount\":250.00,\"cost_date\":\"2026-05-23\"}"
```

### Exemplo: alterar custo
```bash
curl -X PATCH "http://localhost/ERP/api/costs/10" \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"amount\":300.00,\"category\":\"Operacional\"}"
```

### Exemplo: listar custos com filtro
```bash
curl -X GET "http://localhost/ERP/api/costs?page=1&limit=20&category=Operacional&date_from=2026-01-01&date_to=2026-12-31" \
  -H "Authorization: Bearer SEU_TOKEN"
```
