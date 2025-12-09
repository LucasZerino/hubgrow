# HubPHP - Backend Multi-Tenant

Backend PHP para integrações de mensageria (WhatsApp, Instagram, Facebook, Webchat) seguindo a arquitetura do Chatwoot.

## Arquitetura

- **Multi-Tenant**: Isolamento completo de dados por Account
- **SOLID**: Princípios aplicados em toda a arquitetura
- **Clean Code**: Código limpo e bem documentado em PT-BR
- **Idempotência**: Sistema de locks distribuídos e verificação de source_id
- **Real-time**: WebSockets com Laravel Reverb para atualizações instantâneas

## Estrutura

```
backend/
├── app/
│   ├── Models/          # Models com global scopes para multi-tenancy
│   ├── Services/        # Serviços de negócio (SOLID)
│   ├── Jobs/            # Jobs assíncronos com idempotência
│   ├── Controllers/     # Controllers RESTful
│   └── Support/         # Helpers e utilitários (Current, Redis, etc)
├── docker/              # Configurações Docker
└── database/
    └── migrations/      # Migrations do banco de dados
```

## Tecnologias

- Laravel 12
- PostgreSQL
- Redis (filas e locks)
- Docker & Docker Compose
- Laravel Reverb (WebSockets)
- Laravel Echo (Frontend)

## Setup

### Desenvolvimento

```bash
cd docker
docker-compose -f docker-compose-dev.yml up -d
```

### Produção

```bash
cd docker
docker-compose up -d
```

## WebSockets (Laravel Reverb)

O sistema utiliza Laravel Reverb para comunicação em tempo real com o frontend.

### Configuração

As variáveis de ambiente para Reverb devem estar configuradas no `.env`:
```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=152328
REVERB_APP_KEY=ykvaxxhsidezqrh4jv59
REVERB_APP_SECRET=xqtuix8xs8kkrbaiwlkq
REVERB_HOST="0.0.0.0"
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Iniciar Servidor

```bash
# Iniciar servidor Reverb
php artisan reverb:start

# Iniciar fila para processar eventos
php artisan queue:work
```

### Canais Suportados

- `private-account.{accountId}` - Eventos específicos de uma conta

### Eventos Suportados

- `message.created` - Nova mensagem criada
- `message.updated` - Mensagem atualizada
- `message.deleted` - Mensagem deletada
- `conversation.created` - Nova conversa criada
- `conversation.updated` - Conversa atualizada

## Princípios Aplicados

### SOLID

- **Single Responsibility**: Cada classe tem uma única responsabilidade
- **Open/Closed**: Extensível via interfaces e traits
- **Liskov Substitution**: Channels implementam interface comum
- **Interface Segregation**: Interfaces específicas (Channelable)
- **Dependency Inversion**: Dependências de abstrações

### Clean Code

- Nomes descritivos
- Funções pequenas e focadas
- Comentários em PT-BR
- DRY (Don't Repeat Yourself)
- Testes unitários

## API - Documentação

### Criar Inbox

Cria um novo inbox e seu channel associado automaticamente (seguindo padrão Chatwoot).

**Endpoint:** `POST /api/v1/accounts/{account_id}/inboxes`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Campos obrigatórios:**
- `name` (string, max:255): Nome do inbox
- `channel_type` (string): Tipo do canal. Valores aceitos:
  - `App\Models\Channel\InstagramChannel`
  - `App\Models\Channel\WebWidgetChannel`
  - `App\Models\Channel\WhatsAppChannel`
  - `App\Models\Channel\FacebookChannel`

**Campos opcionais:**
- `email_address` (string, email): Email do inbox
- `business_name` (string, max:255): Nome do negócio
- `timezone` (string, max:50): Fuso horário (padrão: UTC)
- `greeting_enabled` (boolean): Habilitar mensagem de boas-vindas
- `greeting_message` (string): Mensagem de boas-vindas
- `out_of_office_message` (string): Mensagem de ausência
- `working_hours_enabled` (boolean): Habilitar horário de funcionamento
- `enable_auto_assignment` (boolean): Habilitar atribuição automática
- `auto_assignment_config` (array): Configuração de atribuição automática
- `allow_messages_after_resolved` (boolean): Permitir mensagens após resolução
- `lock_to_single_conversation` (boolean): Bloquear para uma única conversa
- `csat_survey_enabled` (boolean): Habilitar pesquisa de satisfação
- `csat_config` (array): Configuração da pesquisa de satisfação
- `enable_email_collect` (boolean): Habilitar coleta de email
- `sender_name_type` (integer): Tipo de nome do remetente

**Campos específicos por tipo de canal:**

**Instagram:**
- `webhook_url` (string, url, opcional): URL do webhook do frontend

**WebWidget:**
- `website_url` (string, url, opcional): URL do website (se não fornecido, usa placeholder)

**Importante:**
- ⚠️ **Não envie `channel_id`** - O channel é criado automaticamente
- Canais que requerem OAuth/credenciais (Instagram, WhatsApp, Facebook) começam como **inativos** até serem conectados
- WebWidget fica ativo imediatamente após criação

**Exemplo de requisição - Instagram:**
```json
{
  "name": "Meu Inbox Instagram",
  "channel_type": "App\\Models\\Channel\\InstagramChannel",
  "webhook_url": "https://seu-frontend.com/webhook",
  "timezone": "America/Sao_Paulo"
}
```

**Exemplo de requisição - WebWidget:**
```json
{
  "name": "Meu Inbox WebWidget",
  "channel_type": "App\\Models\\Channel\\WebWidgetChannel",
  "website_url": "https://meusite.com",
  "timezone": "America/Sao_Paulo"
}
```

**Exemplo de requisição - WhatsApp:**
```json
{
  "name": "Meu Inbox WhatsApp",
  "channel_type": "App\\Models\\Channel\\WhatsAppChannel",
  "timezone": "America/Sao_Paulo"
}
```

**Resposta de sucesso (201):**
```json
{
  "id": 1,
  "name": "Meu Inbox Instagram",
  "account_id": 1,
  "channel_type": "App\\Models\\Channel\\InstagramChannel",
  "channel_id": 1,
  "is_active": false,
  "timezone": "America/Sao_Paulo",
  "channel": {
    "id": 1,
    "account_id": 1,
    "instagram_id": "temp_1_1234567890_abc123",
    "webhook_url": "https://seu-frontend.com/webhook"
  }
}
```

**Respostas de erro:**
- `400`: Dados inválidos
- `401`: Não autenticado
- `402`: Limite de inboxes excedido
- `403`: Sem acesso à conta
- `503`: App não configurada (credenciais do SuperAdmin)

## Testes

### Testar conexão WebSocket

```bash
# Testar conexão WebSocket
php artisan websocket:test
```

### Executar testes

```bash
# Executar todos os testes
php artisan test

# Executar testes específicos
php artisan test --filter InboxesTest
```