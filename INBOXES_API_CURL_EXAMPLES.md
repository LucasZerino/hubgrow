# Exemplos de cURL para API de Inboxes

## 📋 Estrutura das Rotas

Todas as rotas estão sob o prefixo: `/api/v1/accounts/{account_id}`

### Rotas Principais:
- **Listar/Criar**: `inboxes` (plural)
- **Operações individuais**: `inbox/{inbox_id}` (singular)

### Rotas de Compatibilidade (Alias):
- `inboxes/{inbox_id}` também funciona (deprecated, mas mantido para compatibilidade)

---

## 🔐 Autenticação

Todas as rotas requerem autenticação via Bearer Token:

```bash
Authorization: Bearer {seu_token_sanctum}
```

---

## 📝 Exemplos de cURL

### 1. Listar todos os inboxes da account

```bash
curl -X GET "http://localhost:8000/api/v1/accounts/1/inboxes" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer seu_token_aqui"
```

**Resposta esperada:**
```json
[
  {
    "id": 1,
    "account_id": 1,
    "name": "Instagram Inbox",
    "channel_type": "App\\Models\\Channel\\InstagramChannel",
    "channel_id": 1,
    "is_active": true,
    "channel": { ... },
    ...
  }
]
```

---

### 2. Criar um novo inbox (Instagram)

```bash
curl -X POST "http://localhost:8000/api/v1/accounts/1/inboxes" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer seu_token_aqui" \
  -d '{
    "name": "Meu Instagram Inbox",
    "channel_type": "App\\Models\\Channel\\InstagramChannel",
    "webhook_url": "https://meusite.com/webhook/instagram",
    "email_address": "contato@exemplo.com",
    "greeting_enabled": true,
    "greeting_message": "Olá! Como posso ajudar?",
    "is_active": false
  }'
```

**Campos obrigatórios:**
- `name`: Nome do inbox
- `channel_type`: Tipo do canal (veja exemplos abaixo)

**Campos opcionais para Instagram:**
- `webhook_url`: URL do webhook (opcional)

---

### 3. Criar um novo inbox (WebWidget)

```bash
curl -X POST "http://localhost:8000/api/v1/accounts/1/inboxes" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer seu_token_aqui" \
  -d '{
    "name": "Widget do Site",
    "channel_type": "App\\Models\\Channel\\WebWidgetChannel",
    "website_url": "https://meusite.com",
    "email_address": "suporte@exemplo.com",
    "greeting_enabled": true,
    "greeting_message": "Bem-vindo! Como podemos ajudar?",
    "is_active": true
  }'
```

**Campos opcionais para WebWidget:**
- `website_url`: URL do site (se não fornecido, usa placeholder)

---

### 4. Criar um novo inbox (WhatsApp)

```bash
curl -X POST "http://localhost:8000/api/v1/accounts/1/inboxes" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer seu_token_aqui" \
  -d '{
    "name": "WhatsApp Business",
    "channel_type": "App\\Models\\Channel\\WhatsAppChannel",
    "email_address": "whatsapp@exemplo.com",
    "is_active": false
  }'
```

---

### 5. Criar um novo inbox (Facebook)

```bash
curl -X POST "http://localhost:8000/api/v1/accounts/1/inboxes" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer seu_token_aqui" \
  -d '{
    "name": "Facebook Page",
    "channel_type": "App\\Models\\Channel\\FacebookChannel",
    "webhook_url": "https://meusite.com/webhook/facebook",
    "email_address": "facebook@exemplo.com",
    "is_active": false
  }'
```

**Campos opcionais para Facebook:**
- `webhook_url`: URL do webhook (opcional)

---

### 6. Mostrar um inbox específico (rota principal - singular)

```bash
curl -X GET "http://localhost:8000/api/v1/accounts/1/inbox/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer seu_token_aqui"
```

---

### 7. Mostrar um inbox específico (rota de compatibilidade - plural)

```bash
curl -X GET "http://localhost:8000/api/v1/accounts/1/inboxes/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer seu_token_aqui"
```

**Resposta esperada:**
```json
{
  "id": 1,
  "account_id": 1,
  "name": "Meu Instagram Inbox",
  "channel_type": "App\\Models\\Channel\\InstagramChannel",
  "channel_id": 1,
  "is_active": false,
  "channel": {
    "id": 1,
    "account_id": 1,
    "instagram_id": "temp_1_1234567890_abc123",
    "webhook_url": "https://meusite.com/webhook/instagram",
    ...
  },
  "account": { ... },
  ...
}
```

---

### 8. Atualizar um inbox (PUT)

```bash
curl -X PUT "http://localhost:8000/api/v1/accounts/1/inbox/1" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer seu_token_aqui" \
  -d '{
    "name": "Nome Atualizado",
    "email_address": "novo@exemplo.com",
    "greeting_enabled": false,
    "is_active": true,
    "channel": {
      "webhook_url": "https://novosite.com/webhook"
    }
  }'
```

**Campos atualizáveis:**
- `name`
- `email_address`
- `business_name`
- `timezone`
- `greeting_enabled`
- `greeting_message`
- `out_of_office_message`
- `working_hours_enabled`
- `enable_auto_assignment`
- `auto_assignment_config`
- `allow_messages_after_resolved`
- `lock_to_single_conversation`
- `csat_survey_enabled`
- `csat_config`
- `enable_email_collect`
- `sender_name_type`
- `channel.webhook_url` (para Instagram/Facebook)
- `channel.website_url` (para WebWidget)

---

### 9. Atualizar um inbox (PATCH)

```bash
curl -X PATCH "http://localhost:8000/api/v1/accounts/1/inbox/1" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer seu_token_aqui" \
  -d '{
    "name": "Apenas o nome foi alterado"
  }'
```

---

### 10. Deletar um inbox

```bash
curl -X DELETE "http://localhost:8000/api/v1/accounts/1/inbox/1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer seu_token_aqui"
```

**Resposta esperada:**
```json
{
  "message": "Inbox deletado com sucesso"
}
```

**Nota:** O channel associado também será deletado automaticamente.

---

## 📌 Tipos de Canal Suportados

| Tipo de Canal | channel_type |
|--------------|--------------|
| Instagram | `App\\Models\\Channel\\InstagramChannel` |
| WhatsApp | `App\\Models\\Channel\\WhatsAppChannel` |
| Facebook | `App\\Models\\Channel\\FacebookChannel` |
| WebWidget | `App\\Models\\Channel\\WebWidgetChannel` |

---

## ⚠️ Observações Importantes

1. **Autenticação obrigatória**: Todas as rotas requerem token Bearer válido
2. **Account ID**: O `account_id` na URL deve corresponder à account do usuário autenticado
3. **Inbox ID**: Use números inteiros para `inbox_id` (as rotas têm constraint `[0-9]+`)
4. **Rotas duplicadas**: Tanto `inbox/{inbox_id}` quanto `inboxes/{inbox_id}` funcionam, mas prefira a primeira (singular)
5. **Criação de Channel**: O channel é criado automaticamente ao criar o inbox - não envie `channel_id`
6. **Status inicial**: Canais que requerem OAuth (Instagram, WhatsApp, Facebook) começam com `is_active: false`
7. **Debug**: Se encontrar problemas, verifique os logs do Laravel para detalhes sobre a busca do inbox

---

## 🔍 Exemplos com Variáveis de Ambiente

```bash
# Definir variáveis
export API_URL="http://localhost:8000"
export TOKEN="seu_token_aqui"
export ACCOUNT_ID=1
export INBOX_ID=1

# Listar inboxes
curl -X GET "${API_URL}/api/v1/accounts/${ACCOUNT_ID}/inboxes" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}"

# Criar inbox
curl -X POST "${API_URL}/api/v1/accounts/${ACCOUNT_ID}/inboxes" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{
    "name": "Novo Inbox",
    "channel_type": "App\\Models\\Channel\\WebWidgetChannel"
  }'

# Mostrar inbox
curl -X GET "${API_URL}/api/v1/accounts/${ACCOUNT_ID}/inbox/${INBOX_ID}" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}"

# Atualizar inbox
curl -X PUT "${API_URL}/api/v1/accounts/${ACCOUNT_ID}/inbox/${INBOX_ID}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{"name": "Nome Atualizado"}'

# Deletar inbox
curl -X DELETE "${API_URL}/api/v1/accounts/${ACCOUNT_ID}/inbox/${INBOX_ID}" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}"
```

---

## 🐛 Tratamento de Erros

### Erro 401 - Não autenticado
```json
{
  "message": "Unauthenticated."
}
```

### Erro 404 - Inbox não encontrado
```json
{
  "error": "Inbox não encontrado",
  "message": "O inbox especificado não foi encontrado nesta conta."
}
```

### Erro 422 - Validação falhou
```json
{
  "error": "Dados inválidos",
  "message": "Os dados fornecidos não são válidos.",
  "errors": {
    "name": ["O campo name é obrigatório."]
  }
}
```

### Erro 500 - Erro interno
```json
{
  "error": "Erro ao buscar inboxes",
  "message": "Mensagem de erro detalhada"
}
```

