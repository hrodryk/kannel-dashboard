# Kannel SMPP Dashboard

Painel de monitoramento em tempo real para gateways Kannel SMS/SMPP, construído com **Laravel 13 + Inertia.js v3 + Vue 3 + Tailwind CSS v4**. Fornece visibilidade unificada sobre múltiplas instâncias do Kannel, incluindo throughput de mensagens, estado das conexões SMSC, saúde das filas, e alertas configuráveis.

---

## Índice

- [Funcionalidades](#funcionalidades)
- [Stack Tecnológica](#stack-tecnológica)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
    - [Desenvolvimento Local](#desenvolvimento-local)
    - [Docker Compose](#docker-compose)
- [Configuração](#configuração)
    - [Variáveis de Ambiente Principais](#variáveis-de-ambiente-principais)
    - [Permissões](#permissões)
- [Uso](#uso)
    - [Comandos Artisan](#comandos-artisan)
    - [Agendador (Scheduler)](#agendador-scheduler)
- [Segurança](#segurança)
- [Testes](#testes)
- [Qualidade de Código](#qualidade-de-código)
- [Estrutura do Projeto](#estrutura-do-projeto)
- [CI/CD](#cicd)
- [Licença](#licença)

---

## Funcionalidades

### 📊 Dashboard em Tempo Real

- **KPIs globais**: contagem de endpoints saudáveis/degradados, SMSCs ativas, throughput MO/MT total, mensagens falhas e total de filas
- **Grid por SMSC**: estado da conexão (conectada/desconectada/ligando), latência, contadores MO/MT acumulados e taxas calculadas por segundo
- **Gráficos de throughput**: séries temporais para janelas de 1h, 24h e 7 dias com buckets agregados horários
- **Monitoramento de filas**: inbound, outbound, DLR, mensagens em buffer e armazenadas (store-forward)
- **Atualização automática**: coleta LIVE a cada requisição do dashboard com skeletons durante carregamentos adiados (`Inertia::defer`)
- **Timeline de alertas**: eventos recentes com severidade, estado (firing/resolved) e métricas contextuais

### 🔌 Gerenciamento de Endpoints

- Cadastro de múltiplas instâncias Kannel (URL base, credenciais admin Basic Auth ou API Key customizada)
- Polling individual com intervalo e timeout configuráveis por endpoint
- Teste de conectividade direto pela UI (`POST /endpoints/{id}/test`)
- Estados do endpoint: `Healthy`, `Degraded`, `Unreachable`, `Disabled`, `Idle`
- Marcação automática como `Unreachable` após N falhas consecutivas configurável (padrão: 3)

### 🚨 Sistema de Alertas

4 tipos de regra configuráveis por endpoint:

| Tipo                             | Descrição                                                                                                   |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `SMSC Disconnection`             | Dispara quando uma SMSC específica (ou qualquer uma) fica no estado desconectado                            |
| `Queue Length Threshold`         | Dispara quando a soma das filas ultrapassa limiares de warning / critical                                   |
| `Throughput Drop Below Baseline` | Compara throughput atual com a média das últimas N horas; dispara se cair abaixo de uma fração (padrão 50%) |
| `Kannel Endpoint Unreachable`    | Dispara imediatamente quando um endpoint atinge o estado `Unreachable`                                      |

Canais de notificação suportados via Laravel Notifications:

- **E-mail** (configuração padrão `MAIL_*`)
- **Webhook JSON genérico** (com assinatura HMAC opcional)
- Extensível via `AlertChannelType` + novos canais do Laravel

Deduplicação por _fingerprint_ (regra + dimensão) e cooldown configurável por regra para evitar notificações duplicadas em flapping.

### 📦 Armazenamento Histórico

- Snapshots brutos por polling com até 30 dias de retenção (configurável)
- Tabela `kannel_hourly_rollups` para agregados horários (gráficos longos sem escanear milhões de linhas)
- `kannel:prune` remove dados antigos de forma segura em cascata
- Métricas de SMSC vinculadas a cada snapshot individual

### 👥 Autenticação e Autorização

Integrado com **Laravel Fortify** + **Laravel Passkeys** de fábrica:

- Login por e-mail/senha
- 2FA via TOTP (Google Authenticator/Authy) com códigos de recuperação
- Passkeys (WebAuthn) para autenticação sem senha
- Gate `manage_kannel` separa permissão de visualização vs. gerenciamento de endpoints e regras

### 📱 UI/UX

- Design responsivo com Tailwind v4 (desktop → 375px mobile)
- Componentes Shadcn/Vue customizados (Sidebar, Card, Badge, Dialog, Select, Tooltip…)
- Modo claro/escuro via `useAppearance`
- Feedback visual por severidade: cores, badges e ícones para `Info / Warning / Critical`

---

## Stack Tecnológica

| Camada           | Tecnologia                       | Versão |
| ---------------- | -------------------------------- | ------ |
| Runtime PHP      | PHP                              | ^8.5   |
| Framework        | Laravel                          | ^13.17 |
| Renderização SPA | Inertia.js (Laravel + Vue3)      | ^3.0   |
| Frontend         | Vue.js                           | ^3.5   |
| Estilização      | Tailwind CSS                     | ^4.1   |
| Componentes UI   | Reka UI + Shadcn/Vue (custom)    | —      |
| Ícones           | Lucide Vue                       | ^1.17  |
| Autenticação     | Laravel Fortify                  | ^1.37  |
| Passkeys         | @laravel/passkeys                | ^0.2   |
| Tipagem rotas TS | Laravel Wayfinder                | ^0.1   |
| Build            | Vite + Vite Plus                 | ^8.0   |
| Banco de Dados   | SQLite (dev) / PostgreSQL (prod) | —      |
| Cache/Fila       | Redis (recomendado prod)         | ^7.0   |
| Testes           | Pest PHP                         | ^5.1   |
| Formatação       | Laravel Pint                     | ^1.27  |
| Análise Estática | Larastan (PHPStan)               | ^3.9   |
| Containerização  | Docker + docker-compose          | —      |

---

## Requisitos

- PHP 8.5+ com extensões: `mbstring`, `xml`, `curl`, `pdo_sqlite` e/ou `pdo_pgsql`, `redis` (opcional)
- Node.js 22+ e npm/pnpm
- Composer 2+
- **Kannel**: instância(s) rodando com interface admin HTTP acessível (porta padrão 13000) e autenticação Basic Auth habilitada (extremamente recomendado)

---

## Instalação

### Desenvolvimento Local

```bash
# 1. Clone o repositório
git clone <url-do-repo>
cd kannel-dashboard

# 2. Setup automático (instala deps, copia .env, gera key, migra, builda)
composer setup

# OU manualmente:
#   composer install
#   cp .env.example .env
#   php artisan key:generate
#   php artisan migrate
#   npm install && npm run build

# 3. Inicie o servidor de desenvolvimento
composer dev
# Executa: php artisan dev (sail + vite, ou servidor embutido)
```

Acesse `http://localhost:8000`. Para produção externa, defina `APP_URL` corretamente.

### Docker Compose

Ambiente pronto para produção com Nginx + PHP-FPM, PostgreSQL 16 e Redis 7:

```bash
# Copie e ajuste as variáveis
cp .env.example .env
# Edite APP_KEY (gere com `php artisan key:generate --show`), DB_*, MAIL_*

# Suba todos os serviços
docker compose up --build -d

# Acompanhe
docker compose logs -f app
```

A aplicação ficará disponível em `http://localhost:8080`. Volumes persistentes criados:

- `postgres_data` — banco
- `redis_data` — cache/filas
- `storage_data` — storage/framework/logs do Laravel

Healthcheck integrado em `/up`.

---

## Configuração

### Variáveis de Ambiente Principais

Todas as configurações específicas do Kannel ficam em `config/kannel.php` e podem ser sobrescritas via `.env`:

```dotenv
# ===== Polling & Retenção =====
KANNEL_POLL_INTERVAL=30              # Intervalo padrão de polling em segundos (fallback)
KANNEL_RETENTION_DAYS=30             # Dias para retenção de snapshots/rollups
KANNEL_UNREACHABLE_AFTER=3           # N falhas consecutivas → Unreachable

# ===== HTTP Client =====
KANNEL_HTTP_TIMEOUT=10               # Timeout total por requisição (s)
KANNEL_HTTP_CONNECT_TIMEOUT=5        # Timeout de handshake TCP (s)
KANNEL_HTTP_RETRY_MAX=4              # Máximo de retentativas exponenciais
KANNEL_HTTP_RETRY_BASE=1             # Backoff base (1s, 2s, 4s, 8s…)
KANNEL_HTTP_RETRY_CAP=30             # Teto máximo do backoff em segundos

# ===== Caminhos Admin do Kannel =====
KANNEL_STATUS_PATH_XML=/status.xml
KANNEL_STATUS_PATH_JSON=/status.json
# (store-status é lido de config/kannel.admin.store_status_path_*)

# ===== Limiares Padrão =====
KANNEL_QUEUE_WARNING=100             # Warning UI para fila total
KANNEL_QUEUE_CRITICAL=1000           # Critical UI para fila total

KANNEL_BASELINE_HOURS=24             # Janela de referência p/ throughput baseline
KANNEL_THROUGHPUT_DROP_FRACTION=0.5  # Fração mínima tolerada (0.5 = 50% do baseline)
KANNEL_RATE_WINDOW_MINUTES=5         # Janela de cálculo de taxa em memória

# ===== Webhook de Alerta =====
KANNEL_WEBHOOK_HMAC_HEADER=X-Kannel-Signature
KANNEL_WEBHOOK_HMAC_ALGO=sha256
KANNEL_WEBHOOK_TIMEOUT=10
```

> ⚠️ O campo `url` de `KannelEndpoint` armazena **apenas a base** (`scheme://host:port`). Sufixos como `/status.xml` são removidos automaticamente no setter e anexados dinamicamente por `statusUrl()` / `storeStatusUrl()`.

### Permissões

A gate `manage_kannel` (coluna booleana `can_manage_kannel` na tabela `users`) controla:

- Acesso às telas: `/endpoints`, `/alert-rules`, `/store-status`
- Ações: criar/editar/excluir endpoints e regras
- O dashboard principal é acessível por qualquer usuário autenticado e verificado (leitura)

Para promover o primeiro usuário admin:

```bash
php artisan tinker --execute 'User::where("email","seu@email.com")->update(["can_manage_kannel" => true]);'
```

---

## Uso

### Comandos Artisan

| Comando                    | Descrição                                                                                                  |
| -------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `kannel:poll`              | Poll de todos os endpoints habilitados, persiste snapshots, calcula taxas, avalia alertas e executa rollup |
| `kannel:poll --endpoint=5` | Poll apenas o endpoint ID 5                                                                                |
| `kannel:rollup`            | Reagrega snapshots brutos em buckets horários (`kannel_hourly_rollups`)                                    |
| `kannel:prune`             | Remove snapshots + SMSC metrics + rollups mais antigos que `KANNEL_RETENTION_DAYS`                         |
| `kannel:prune --days=7`    | Override temporário da janela de retenção                                                                  |

### Agendador (Scheduler)

Em `routes/console.php` o Scheduler já registra (ajuste conforme sua necessidade):

```bash
# Cron do sistema a cada minuto (requerido)
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

Padrões típicos:

- `kannel:poll` → a cada minuto (o comando respeita `poll_interval_seconds` por endpoint via `last_polled_at`)
- `kannel:rollup` → a cada 10 minutos
- `kannel:prune` → diariamente às 03:00

No Docker com Supervisord (`.docker/supervisord.conf`) o scheduler já roda como daemon junto ao php-fpm.

---

## Segurança

- **Credenciais em repouso**: `admin_password` e `admin_api_key_value` são criptografadas com `Crypt::encryptString()` no momento do `set` e descriptografadas somente no `get`. São `#[Hidden]` do modelo e **nunca** serializadas para Inertia, JSON ou logs.
- **Mascaramento em logs**: o HttpClient registra falhas com _código de status e mensagem_, mas não repete payloads de autorização no contexto do log.
- **HMAC em webhooks**: payload de notificação recebe assinatura hexadecimal HMAC no header configurado usando o `secret` da regra como chave. Verifique no receptor para prevenir requisições forjadas.
- **CSRF + CSP**: já tratados por padrão pelo Laravel + Inertia.
- **Sessões criptografadas**: `SESSION_ENCRYPT=true` recomendado em produção.
- **2FA e Passkeys**: auto-serviço em `/settings/security`.

---

## Testes

O projeto usa **Pest** com o plugin oficial Laravel. Mais de 30 suítes cobrem polling, parsing XML, credenciais, alertas, webhooks, performance e auditoria de segurança.

```bash
# Rodar suite completa
php artisan test --compact
# ou diretamente:
vendor/bin/pest

# Filtrar arquivo específico
php artisan test tests/Feature/KannelAlertingTest.php --compact

# Filtrar por nome
vendor/bin/pest --filter=smsc_down_rule_fires_notification
```

**Fixtures reais** de respostas Kannel (svn-r5173) ficam em `tests/Fixtures/Kannel/` e são usadas nos testes de parser sem depender de instância real.

---

## Qualidade de Código

```bash
# Formatação (Laravel Pint - PSR-12 + conventions)
composer lint            # corrige arquivos
composer lint:check      # apenas valida

# Análise estática PHPStan level 8
composer types:check     # phpstan analyse

# Pipeline completo (lint + types + testes)
composer ci:check
```

Frontend:

```bash
npm run check            # Lint/TypeCheck frontend
npm run types:check      # vue-tsc --noEmit
```

---

## Estrutura do Projeto

```
app/
├── Console/Commands/
│   ├── KannelPollCommand.php       # Orquestra polling + rollup + alertas
│   ├── KannelRollupCommand.php     # Agregação horária de snapshots
│   └── KannelPruneCommand.php      # Limpeza de dados antigos
├── DataObjects/Kannel/             # DTOs imutáveis (Snapshot, SmscMetrics…)
├── Enums/                          # EndpointStatus, AlertSeverity, AlertRuleType, SMSCState
├── Http/Controllers/
│   ├── KannelDashboardController.php    # Página principal + coleta LIVE
│   ├── KannelEndpointsController.php    # CRUD endpoints + teste conexão
│   ├── KannelAlertRulesController.php   # CRUD regras de alerta
│   └── KannelStoreStatusController.php  # Visualiza store-forward
├── Models/
│   ├── KannelEndpoint.php         # Model principal (URLs normalizadas, credenciais cripto)
│   ├── KannelMetricSnapshot.php   # Snapshot bruto por polling
│   ├── KannelSmscMetric.php       # Métricas por SMSC (1:N com snapshot)
│   ├── KannelHourlyRollup.php     # Buckets agregados horários
│   ├── KannelAlertRule.php        # Regra config com threshold + canais
│   └── KannelAlertEvent.php       # Ocorrências de firing/resolved por fingerprint
├── Notifications/
│   ├── KannelAlertNotification.php    # Mensagem formatada p/ múltiplos canais
│   └── Channels/WebhookChannel.php    # Canal customizado webhook + HMAC
└── Services/Kannel/
    ├── HttpClient.php              # Client HTTP com retry exponencial
    ├── MetricsCollector.php        # Orquestra fetch → parse → persist
    ├── XmlStatusParser.php         # Parser status.xml (Kannel svn-r5173+)
    ├── JsonStatusParser.php        # Parser status.json fallback
    ├── StoreStatusXmlParser.php    # Parser /store-status.xml
    └── Alerting/
        ├── AlertManager.php        # Gerencia lifecycle de eventos (fire/resolve/notify)
        ├── RuleEvaluator.php       # Resolve estratégia e executa
        └── Rules/*Strategy.php     # smsc_down, queue_exceeded, throughput_drop, unreachable

resources/js/
├── pages/
│   ├── Dashboard.vue               # Painel principal (KPIs, grid, gráficos, filas, alertas)
│   └── kannel/
│       ├── Endpoints.vue           # Tabela CRUD endpoints
│       ├── AlertRules.vue          # Tabela CRUD regras de alerta
│       └── StoreStatus.vue         # View detalhada store-status
├── components/ui/                  # Biblioteca Shadcn/Vue (Card, Dialog, Sidebar…)
├── composables/                    # useAppearance, useTwoFactorAuth…
└── layouts/                        # AppLayout (sidebar + header), AuthLayout, SettingsLayout
```

---

## CI/CD

O workflow em `.github/workflows/tests.yml` executa em cada push/PR:

1. Checkout do código
2. Setup PHP 8.5 + Node 22
3. `composer setup` (instalação, key, migrações, build assets)
4. `composer ci:check` (lint check → phpstan → testes completos)

Dependabot habilitado para dependências Composer e npm.

---

## Licença

Este projeto é open-source sob a licença **MIT**.
