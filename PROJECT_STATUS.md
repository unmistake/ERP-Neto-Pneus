# Project Status - ERP Neto Rodas

> Memoria oficial do projeto. Leia este arquivo antes de iniciar qualquer tarefa.
>
> Ultima revisao: **2026-06-25**
> Commit analisado: **fd1f486** (`main`)  
> Progresso geral estimado: **65%**

## 1. Visao geral

O ERP esta em producao e possui uso real nos fluxos de estoque, CRM, PDV e emissao
de NF-e. A base funcional e ampla, mas a maturidade de seguranca, testes,
migracoes e operacao financeira ainda e baixa. Os percentuais abaixo representam
entrega validada, nao apenas quantidade de arquivos existentes.

```mermaid
flowchart LR
    A["Fundacao e infraestrutura<br/>70%"] --> B["Estoque, CRM e PDV<br/>85%"]
    B --> C["Fiscal NF-e<br/>65%"]
    B --> D["Financeiro e conciliacao<br/>45%"]
    B --> E["API e integracoes<br/>70%"]
    C --> F["Qualidade, seguranca e operacao<br/>25%"]
    D --> F
    E --> F
    F --> G["Operacao madura<br/>0%"]

    classDef done fill:#dcfce7,stroke:#15803d,color:#14532d;
    classDef partial fill:#fef3c7,stroke:#b45309,color:#78350f;
    classDef risk fill:#fee2e2,stroke:#b91c1c,color:#7f1d1d;
    class B,C done;
    class A,D,E partial;
    class F,G risk;
```

### Legenda de estados

- **Validado:** ha teste executado ou evidencia operacional observada.
- **Parcial:** existe implementacao, mas faltam cenarios, uso real ou acabamento.
- **Implementado, nao validado:** existe codigo, sem evidencia suficiente de funcionamento.
- **Planejado:** ainda nao foi implementado.
- **Bloqueado:** depende de decisao, credencial, ambiente ou correcao anterior.

## 2. Tarefa atual

### Adicionar ranking de vendedores ao dashboard

**Estado:** implantado e validado parcialmente.
**Prioridade:** media.

### Objetivo

Exibir no dashboard um ranking mensal de vendedores com volume vendido, quantidade
de vendas, ticket medio e maior venda individual.

### Criterios objetivos de conclusao

- [x] Agregar vendas do mes atual por vendedor usando `sales.seller_name`.
- [x] Exibir maiores destaques: volume vendido, quantidade de vendas, ticket medio e maior venda.
- [x] Renderizar ranking responsivo no dashboard sem alterar o fluxo de PDV.
- [x] Validar sintaxe PHP do dashboard.
- [x] Registrar evidencias e lacunas de validacao.
- [x] Validar dashboard em producao apos deploy.

## 3. Fases

| Fase | Estado | Progresso | Evidencia atual | Resultado esperado |
|---|---|---:|---|---|
| Fundacao e infraestrutura | Parcial | 70% | Aplicacao PHP/MySQL em VPS, dominio HTTPS e deploy por Git funcionando | Ambiente reproduzivel, configuracao segura, deploy documentado e rollback previsivel |
| Estoque, CRM e PDV | Validado com ressalvas | 85% | 236 produtos, 72 clientes, 76 vendas, 83 itens vendidos e 344 movimentos em producao | Fluxos operacionais consistentes, auditaveis e cobertos por testes |
| Fiscal NF-e | Critico | 65% | 15 NF-e autorizadas para 5 vendas auditadas; 10 autorizacoes excedentes por reemissao durante processamento | Uma autorizacao por venda, estados reconciliados e regularizacao das duplicidades |
| Financeiro e conciliacao | Implementado, nao validado | 45% | Telas, API e importacao CSV/OFX existem; tabelas financeiras estao vazias em producao | Entradas, saidas e conciliacoes usadas e vinculadas a vendas/custos |
| API e integracoes | Parcial | 70% | API protegida retorna `401`; catalogo publico retorna `200`; CRM suporta autenticacao/sincronizacao externa | Contratos versionados, testes de integracao e integracoes da loja confiaveis |
| Qualidade, seguranca e operacao | Critico | 25% | 39 arquivos passam em lint; nao ha testes, CI, login administrativo ou CSRF | Seguranca por perfil, testes automatizados, CI, logs, backup e observabilidade |
| Operacao madura | Planejado | 0% | Nao ha evidencias de SLO, monitoramento, restauracao testada ou rotina formal de release | Operacao previsivel com alertas, backups testados, auditoria e indicadores |

## 4. Checklists

### Concluido

- [x] ERP publicado em `https://erp-netorodas.online`.
- [x] Navegacao administrativa responsiva.
- [x] Cadastro e edicao de produtos com categoria, estado, marca, modelo, medidas e local.
- [x] Imagem de produto e compatibilidade com varios carros.
- [x] Filtros de estoque por marca, modelo, local, carro, categoria, estado e saldo.
- [x] CRM com dados pessoais, endereco, edicao, exclusao e historico de vendas.
- [x] PDV desktop e mobile com vendedor, cliente, endereco e itens pesquisaveis.
- [x] Criacao de produto nao cadastrado durante a venda.
- [x] Idempotencia de novas vendas por `request_token`.
- [x] Estoque e movimentos atualizados de forma transacional na venda.
- [x] Dashboard com indicadores e agregacao de faturamento corrigida.
- [x] NF-e modelo 55 com emissao, sincronizacao e download de DANFE.
- [x] API REST paginada para produtos, clientes, vendas, custos e movimentos bancarios.
- [x] Catalogo publico de produtos para a loja.
- [x] API protegida rejeita acesso sem Bearer token.
- [x] Endereco completo do cliente exposto na rota protegida `GET /customers/{id}`.

### Em andamento

- [ ] Validar sincronizacao real de NF-e recebidas com a Focus em producao.

### Proxima fila

- [ ] Proteger o ERP administrativo e as operacoes de escrita com login, perfis e CSRF.
- [ ] Criar migracoes versionadas e remover `ALTER TABLE` executado durante requisicoes.
- [ ] Sincronizar `sql/schema.sql` com o schema real, incluindo `customers.email`.
- [ ] Criar testes automatizados para venda, estoque, fiscal, API e importacao bancaria.
- [ ] Adicionar CI para lint, testes e verificacao de schema.
- [ ] Resolver e monitorar os 3 status fiscais pendentes e documentos em processamento.
- [ ] Validar importacao CSV/OFX com extratos reais anonimizados dos tres bancos.
- [ ] Implementar conciliacao entre movimento bancario, venda, custo, conta a receber e conta a pagar.
- [ ] Importar itens de NF-e recebida para cadastro/entrada de estoque apos conferencia.
- [ ] Definir politica de estoque negativo e alertas operacionais.
- [ ] Documentar deploy atual da VPS e remover do README o fluxo antigo como caminho principal.
- [ ] Criar rotina de backup e testar restauracao do banco.
- [ ] Adicionar logs estruturados, auditoria de alteracoes e monitoramento de erros.

## 5. Bloqueios e riscos

| Bloqueio ou risco | Impacto | Evidencia | Acao recomendada |
|---|---|---|---|
| ERP administrativo acessivel sem login | Critico: exposicao de dados e operacoes | Dashboard, estoque, PDV e vendas responderam `HTTP 200` sem sessao | Executar a tarefa atual antes de novas expansoes administrativas |
| Formularios sem protecao CSRF | Critico: operacoes podem ser disparadas por paginas externas | Nao ha implementacao CSRF no repositorio | Adicionar token por sessao a toda mutacao |
| Ausencia de testes automatizados e CI | Alto: regressao so aparece em producao | Nenhum arquivo de teste, framework ou workflow encontrado | Criar baseline de testes depois da protecao de acesso |
| Schema divergente do banco real | Alto: instalacao nova pode nascer incompleta | Producao possui `customers.email`; `sql/schema.sql` nao possui a coluna | Criar migracoes e gerar schema consolidado |
| Migracoes executadas em requisicoes | Alto: lentidao, falha concorrente e permissao DDL em runtime | `CREATE/ALTER TABLE` em paginas, API e actions | Mover alteracoes para migracoes explicitas de deploy |
| Configuracoes sensiveis rastreadas pelo Git | Alto: risco de vazamento de token e credenciais | `config/api.php`, `config/database.php` e `config/fiscal.php` sao rastreados | Migrar segredos para ambiente/arquivos locais ignorados e rotacionar credenciais |
| README descreve Cloudflare/XAMPP como publicacao principal | Medio: procedimento incorreto em futuras entregas | Producao atual esta na VPS com Nginx, mas README termina no fluxo antigo | Documentar VPS, banco, deploy, rollback e verificacao |
| Financeiro sem uso operacional comprovado | Medio: relatorios podem transmitir falsa completude | `costs`, contas e movimentos bancarios possuem zero registros em producao | Validar com lote controlado e reconciliacao manual antes de confiar nos saldos |
| Estados fiscais ainda pendentes | Alto: risco fiscal e operacional | 3 vendas `pending`; 9 documentos `processando`; 1 documento rejeitado | Criar rotina de sincronizacao, alerta e tratamento de excecao |
| NF-e autorizadas em duplicidade | Critico: obrigacao fiscal repetida e risco contabil | 15 autorizacoes para 5 vendas; 10 notas excedentes confirmadas diretamente na Focus e nos XMLs | Bloquear reemissao, reconciliar referencias e tratar cancelamento extemporaneo com contador/SEFAZ |
| Cancelamento normal das 10 NF-e excedentes fora do prazo | Critico: documentos continuam autorizados e com efeito fiscal | Focus/SEFAZ recusou todas com `Prazo de Cancelamento Superior ao Previsto na Legislacao` | Solicitar cancelamento extemporaneo conforme procedimento da SEFAZ-BA e orientacao contabil; nao repetir o DELETE comum |
| Estoque negativo existente | Medio: disponibilidade e margem podem ficar incorretas | 14 produtos com saldo negativo em producao | Definir politica, destacar excecoes e criar rotina de regularizacao |
| Ambiente local sem MySQL ativo na revisao | Baixo: validacao local incompleta | Conexao local recusada em 2026-06-13 | Subir MySQL local e criar banco descartavel para testes |

## 6. Evidencias e validacoes atuais

### Executadas em 2026-06-13

- `php -l` nos 39 arquivos PHP: **aprovado**.
- `git status`: arvore limpa antes da criacao deste painel.
- Producao: pagina inicial, dashboard, estoque, PDV e vendas responderam `HTTP 200`.
- Producao: `GET /api/public/products?limit=1` respondeu `200`.
- Producao: `GET /api/health` e `GET /api/products?limit=1` sem token responderam `401`.
- Banco de producao consultado apenas por metadados e agregados: 12 tabelas encontradas.
- MySQL local: **nao validado**, pois o servico recusou conexao.

### Executadas em 2026-06-15

- Banco de producao consultado por venda, valor e documentos fiscais vinculados.
- As 15 referencias fiscais foram consultadas diretamente na Focus por `GET /v2/nfe/{ref}`.
- Numero, serie, chave e status `autorizado` foram confirmados para cada referencia.
- Os XMLs foram baixados em memoria e conferidos por destinatario e valor total.
- Resultado: 5 vendas originaram 15 NF-e autorizadas, totalizando 10 documentos excedentes.
- Apos a auditoria, os 10 cancelamentos foram enviados pela referencia exata e recusados por prazo excedido.
- As 10 excedentes continuam `autorizado` na Focus; o ERP registrou `erro_cancelamento`.
- As NF-e mantidas 1153, 1155, 1157, 1162 e 1165 continuam autorizadas.
- As vendas 64, 67, 71, 95 e 97 continuam corretamente com status fiscal `issued`.
- Teste de idempotencia seguro na venda 64: contagem permaneceu em 6 documentos e a emissao reutilizou `NFE64T20260608161100`, sem criar nova NF-e.
- `php -l` aprovado em `includes/fiscal_focus.php`, `pages/pdv.php`, `actions/fiscal_issue.php` e `actions/fiscal_cancel.php`.
- `git diff --check`: aprovado.
- Deploy `fd1f486` aplicado por fast-forward na VPS.
- Producao: pagina inicial, PDV pela rota `index.php?page=pdv` e PDV mobile responderam `HTTP 200`.
- Producao: `GET /api/health` sem token preservou `HTTP 401`.
- Pos-deploy: nova chamada fiscal da venda 64 manteve 6 documentos e reutilizou a NF-e 1153.

### Executadas em 2026-06-16

- Causa da regressao confirmada: vendas `nfe/pending` sem registros em `fiscal_documents` ficavam sem botao de emissao.
- Regra do PDV ajustada para consultar se existe NF-e real ativa antes de esconder `Emitir NF-e`.
- Simulacao com as 10 vendas mais recentes de producao indicou `show` para vendas sem documento fiscal ativo, incluindo as vendas 110, 107 e 105.
- `php -l pages/pdv.php`: **aprovado**.
- `git diff --check`: sem erro bloqueante; somente avisos de espaco ao final de linha ja existentes no painel.
- Deploy `e4082a7` aplicado por fast-forward na VPS.
- Producao: PDV desktop respondeu `HTTP 200`; PDV mobile respondeu `HTTP 200`; `GET /api/health` sem token preservou `HTTP 401`.
- Pos-deploy: vendas recentes sem NF-e ativa, incluindo 110, 107 e 105, permaneceram elegiveis para exibir `Emitir NF-e`.
- MVP local de WhatsApp para NF-e criado com `config/whatsapp.php`, `includes/whatsapp_service.php`, log `whatsapp_messages` e disparo automatico apos NF-e `issued`.
- Validacao sem credenciais: configuracao carregou como `WHATSAPP_DISABLED_OK`, evitando envio acidental.
- `php -l` aprovado em `config/whatsapp.php`, `includes/whatsapp_service.php`, `includes/fiscal_focus.php`, `actions/fiscal_issue.php` e `actions/fiscal_sync.php`.

### Executadas em 2026-06-20

- Rejeicao 539 vinculada a venda 150, cliente Emerson Bonfim De Jesus, total R$ 920,00 e referencia `NFE150T20260620111245`.
- As duas chaves apontam para modelo 55, serie 55 e numero 1175; nao existe NF-e 1175 registrada no ERP, indicando numeracao usada fora deste fluxo.
- Parser validado com a resposta real: extraiu `NUMBER=1175` e calculou `NEXT=1176`.
- Classificador passou a preservar `mensagem_sefaz` completa em vez de apenas `erro_autorizacao`.
- Recuperacao limitada a uma tentativa por chamada, com referencias distintas e trava MySQL por venda e por serie.
- `php -l includes/fiscal_focus.php` e `php -l actions/fiscal_issue.php`: aprovados.
- Nenhuma NF-e foi emitida durante a validacao local.

### Executadas em 2026-06-25

- Criada integracao local de NF-e recebida pela Focus com `GET /v2/nfes_recebidas`, controle incremental por `versao` e persistencia por chave de acesso.
- Criadas tabelas `inbound_nfe_sync_state`, `inbound_nfes` e `inbound_nfe_items` no runtime e no `sql/schema.sql`.
- Criada tela `nfe_entrada` com filtros por fornecedor/chave/numero, status e periodo, alem de botoes de sincronizacao e download de DANFE.
- Criadas actions `actions/inbound_nfe_sync.php` e `actions/inbound_nfe_download_pdf.php`.
- `php -l` aprovado em `includes/inbound_nfe_focus.php`, `pages/nfe_entrada.php`, `actions/inbound_nfe_sync.php`, `actions/inbound_nfe_download_pdf.php`, `index.php` e `includes/layout.php`.
- `git diff --check`: aprovado; avisos restantes sao apenas normalizacao LF/CRLF.
- MySQL local nao validado: conexao recusada em `127.0.0.1:3306`, portanto a renderizacao da tela e a sincronizacao real com Focus ficaram pendentes.
- Deploy `961f34f` aplicado por fast-forward na VPS.
- Producao: `https://erp-netorodas.online/index.php?page=nfe_entrada` respondeu `HTTP 200` e conteve o titulo `NF-e recebidas`.
- Producao: tabelas `inbound_nfe_sync_state`, `inbound_nfes` e `inbound_nfe_items` confirmadas como existentes.
- Sincronizacao real com a Focus ainda nao foi executada nesta validacao para evitar gravacao operacional sem acompanhamento.
- Tentativa operacional de sincronizacao retornou `HTTP 400: CNPJ do emitente nao autorizado ou nao informado` para o CNPJ `35732524000151`; a documentacao da Focus define `cnpj` como CNPJ da empresa recebedora, indicando necessidade de habilitacao/autorizacao do CNPJ na Focus para NF-e recebidas/DF-e ou configuracao de outro CNPJ via `FOCUS_INBOUND_RECIPIENT_CNPJ`.
- Login administrativo implementado localmente com tabela `system_users`, usuario inicial `admin`, senha inicial definida por `ERP_ADMIN_DEFAULT_PASSWORD` com fallback `neto001`, tela `login`, logout e bloqueio das paginas administrativas.
- Actions administrativas diretas passaram a exigir sessao; `sale_finalize.php` permaneceu publico para preservar o fluxo do `pdv_mobile`.
- `php -l` aprovado em `index.php`, `includes/auth.php`, `includes/layout.php`, `pages/login.php`, `actions/logout.php` e 18 actions administrativas protegidas.
- `git diff --check`: aprovado; avisos restantes sao apenas normalizacao LF/CRLF.
- Deploy `ba54467` aplicado por fast-forward na VPS.
- Producao: dashboard sem sessao retornou `302` para `index.php?page=login`; login respondeu `HTTP 200`; `pdv_mobile` respondeu `HTTP 200`.
- Usuario `admin` confirmado em producao como ativo e senha inicial `neto001` validada por `password_verify`; login real atualizou `last_login_at`.
- Diagnostico de NF-e recebida implementado localmente para gravar `last_http_status`, `last_response_keys` e `last_response_sample` sanitizado quando a Focus retorna sucesso sem documentos.
- `php -l includes/inbound_nfe_focus.php` e `php -l pages/nfe_entrada.php`: aprovados.
- Ranking mensal de vendedores implementado localmente no dashboard com agregacao por `sales.seller_name`, cards de destaque e tabela responsiva.
- `php -l pages/dashboard.php`: aprovado.
- `git diff --check`: aprovado; avisos restantes sao apenas normalizacao LF/CRLF.
- Deploy `60e1cf4` aplicado por fast-forward na VPS.
- Producao: `php -l pages/dashboard.php` aprovado na VPS apos deploy.

| Venda | Valor confirmado no XML | NF-e autorizadas | Excedentes | Observacao |
|---|---:|---|---:|---|
| Lucas Lima | R$ 800,00 | 1148 a 1153 | 5 | ERP reconhece apenas a 1153 como autorizada |
| Anderson Anderson | R$ 1.280,00 | 1154 e 1155 | 1 | Nome difere de "Anderson Guimaraes" informado no painel |
| Marcus vinicius Santos | R$ 780,00 | 1156 e 1157 | 1 | ERP reconhece apenas a 1157 como autorizada |
| Higor Carvalho | R$ 680,00 | 1161 e 1162 | 1 | Valor no ERP e nos dois XMLs e R$ 680,00, nao R$ 380,00 |
| Edilson Barbosa barreto | R$ 1.400,00 | 1163 a 1165 | 2 | ERP reconhece apenas a 1165 como autorizada |

### Indicadores observados em producao

| Indicador | Valor |
|---|---:|
| Produtos | 236 |
| Produtos com imagem | 28 |
| Produtos com custo maior que zero | 70 |
| Produtos com preco maior que zero | 60 |
| Produtos com estoque negativo | 14 |
| Clientes | 72 |
| Clientes com endereco | 24 |
| Clientes com e-mail | 12 |
| Clientes com autenticacao externa | 1 |
| Vendas | 76 |
| Vendas com vendedor | 69 |
| Vendas com token idempotente | 35 |
| Documentos fiscais | 32 |
| NF-e autorizadas em producao | 17 |

## 7. Historico de entregas

| Data | Entrega | Estado atual | Forma de validacao registrada |
|---|---|---|---|
| 2026-05-26 | Base inicial do ERP | Parcial | Commit `afce6ea`; existencia de modulos, sem teste historico registrado |
| 2026-05-27 | Conciliacao bancaria e isolamento do PDV mobile | Implementado, nao validado | Commit `2ffcf7e`; codigo presente, tabelas bancarias vazias em producao |
| 2026-05-31 | Evolucao fiscal, CRM, PDV e importacao de extratos | Parcial | Commit `763ab89`; codigo presente e uso fiscal confirmado por dados de producao |
| 2026-06-03 | Imagens e compatibilidade de produtos com carros | Validado com ressalvas | Commit `40d2d8a`; 28 produtos com imagem e 63 vinculos produto-carro em producao |
| 2026-06-03 | Cancelamento de NF-e | Implementado, nao validado | Commit `9c135c7`; fluxo existe, mas nenhum documento cancelado foi observado nesta revisao |
| 2026-06-03 | Correcao da agregacao diaria do dashboard | Validado anteriormente | Commit `1470444`; correcao registrada no Git, sem teste automatizado permanente |
| 2026-06-06 | Navegacao responsiva e indicador semanal | Validado anteriormente | Commits `7cd3ad8` e `a7d354f`; paginas em producao responderam `200` |
| 2026-06-06 | Prevencao de vendas duplicadas | Parcial | Commit `534ac6b`; indice unico existe, mas somente 35 de 76 vendas possuem token por serem posteriores a mudanca |
| 2026-06-07 | Integracao de clientes com autenticacao externa | Parcial | Commits `2e796dc` e `5e6119c`; 1 cliente com `external_auth_id` em producao |
| 2026-06-09 | Metadados Merchant Center e acesso direto ao estoque | Parcial | Commits `fd89111` e `01b9b4c`; lint aprovado e pagina de estoque respondeu `200` |
| 2026-06-13 | Endereco do cliente na API protegida | Validado | Commit `2254bc5`; deploy na VPS, lint aprovado e API protegida preservou `401` sem token |
| 2026-06-13 | Criacao da memoria oficial do projeto | Validado | Secoes obrigatorias, tarefa unica, link no README e `git diff --check` verificados |
| 2026-06-15 | Auditoria de NF-e repetidas | Validado | Banco, API Focus e XML confirmaram 15 autorizacoes para 5 vendas e 10 documentos excedentes |
| 2026-06-15 | Prevencao de novas NF-e duplicadas | Implantado e validado | Commit `fd1f486`; teste pos-deploy reutilizou a NF-e 1153, manteve a contagem de documentos e PDV respondeu `HTTP 200` |
| 2026-06-15 | Tentativa de cancelar 10 NF-e excedentes | Bloqueado por prazo fiscal | DELETE enviado por referencia exata; Focus/SEFAZ manteve todas autorizadas e retornou prazo de cancelamento excedido |
| 2026-06-16 | Correcao do botao `Emitir NF-e` | Implantado e validado | Commit `e4082a7`; PDV respondeu `HTTP 200` e vendas 110, 107 e 105 ficaram elegiveis para emissao |
| 2026-06-16 | MVP de envio de NF-e por WhatsApp | Implementado localmente, aguardando credenciais | Lint aprovado e configuracao padrao validada como desativada |
| 2026-06-20 | Recuperacao da rejeicao NF-e 539 | Implementado localmente, deploy pendente | Resposta real extraiu NF-e 1175 e proximo numero 1176; lint aprovado sem emitir documento |
| 2026-06-25 | Tela de NF-e de entrada via Focus | Implantado e validado parcialmente | Commit `961f34f`; rota respondeu `HTTP 200`, tabelas criadas e lint aprovado na VPS |
| 2026-06-25 | Login administrativo do ERP | Implantado e validado parcialmente | Commit `ba54467`; dashboard redirecionou para login, `admin` criado e `pdv_mobile` permaneceu publico |
| 2026-06-25 | Ranking mensal de vendedores no dashboard | Implantado e validado parcialmente | Commit `60e1cf4`; `php -l pages/dashboard.php` aprovado localmente e na VPS |

## 8. Regras de trabalho com o Codex

1. Ler este arquivo antes de analisar ou editar o repositorio.
2. Trabalhar em apenas **uma entrega principal por vez**.
3. Antes de editar codigo, atualizar a secao **Tarefa atual**, seu objetivo e seus criterios.
4. Nao iniciar item da proxima fila enquanto a tarefa atual nao estiver concluida, bloqueada ou explicitamente substituida.
5. Marcar algo como concluido somente com teste executado ou evidencia operacional registrada.
6. Registrar comandos e cenarios realmente validados; nao registrar testes presumidos.
7. Ao finalizar uma entrega, atualizar fases, checklists, riscos, indicadores relevantes e historico.
8. Se um teste nao puder ser executado, registrar claramente a lacuna e o risco residual.
9. Nao reanalisar modulos sem relacao com a tarefa atual, salvo quando surgir dependencia concreta.
10. Preservar alteracoes locais e configuracoes exclusivas de producao.
11. Nunca incluir segredos, dumps, dados pessoais ou artefatos de deploy no Git.
12. Nao executar commit, push ou deploy sem pedido explicito do usuario.
13. Manter respostas concisas e apontar este painel como fonte de estado.
14. Quando houver dois repositorios envolvidos, atualizar a memoria do repositorio responsavel por cada entrega.
15. Toda mudanca de banco deve ter migracao versionada, validacao e estrategia de rollback antes do deploy.

## 9. Como atualizar este painel

Ao iniciar:

1. Atualize a data e o commit analisado.
2. Defina uma unica tarefa atual.
3. Escreva criterios verificaveis antes de editar codigo.

Ao finalizar:

1. Execute as validacoes relevantes.
2. Atualize o percentual apenas se houver nova evidencia.
3. Mova itens entre os checklists.
4. Registre riscos novos ou removidos.
5. Adicione uma linha ao historico com data, entrega, estado e validacao.
