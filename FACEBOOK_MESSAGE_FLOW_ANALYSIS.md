# Análise do Fluxo de Mensagem do Facebook - 25/11/2025

## 📋 Resumo do Problema
A mensagem foi recebida corretamente, mas **falhou ao criar a conversa** devido a um erro de `contact_inbox_id` null no banco de dados.

## 🔍 Mapeamento Completo do Fluxo

### 1. **Recebimento do Webhook** ✅
- **Timestamp**: 2025-11-25 19:57:52
- **Endpoint**: `/api/webhooks/facebook`
- **Status HTTP**: 200 OK
- **Payload recebido**:
  ```json
  {
    "object": "page",
    "entry": [{
      "time": "...",
      "id": "...",
      "messaging": [{
        "sender": {"id": "25865274353075858"},
        "recipient": {"id": "108663328530265"},
        "timestamp": 1764100671570,
        "message": {
          "mid": "m_EeZ7UmlpCFE2gmtZhLsA4KHhSvn4fJqPtDK81ZUL1t-fUmNlvSEmDGTYkYqfYRNkI2YFwXS8YT-UR7AIIt5Frg",
          "text": "Olá"
        }
      }]
    }]
  }
  ```

### 2. **Validação no Controller** ✅
- ✅ Object = "page" (válido)
- ✅ Entry count = 1
- ✅ Messaging count = 1
- ✅ Has sender: true
- ✅ Has recipient: true
- ✅ Has message: true
- ✅ Has text: true
- ✅ Is echo: false (mensagem do contato, não do agente)

### 3. **Enfileiramento do Job** ✅
- **Job**: `FacebookEventsJob`
- **Queue**: `low`
- **Payload**: JSON string do objeto messaging
- **Status**: Enfileirado com sucesso

### 4. **Processamento do Job** ✅
- **Timestamp**: 2025-11-25 19:57:53
- **Parser criado**:
  - ✅ Sender ID: `25865274353075858`
  - ✅ Recipient ID: `108663328530265`
  - ✅ Message ID: `m_EeZ7UmlpCFE2gmtZhLsA4KHhSvn4fJqPtDK81ZUL1t-fUmNlvSEmDGTYkYqfYRNkI2YFwXS8YT-UR7AIIt5Frg`
  - ✅ Is echo: false
  - ✅ Is agent message: false

### 5. **Busca do Canal e Inbox** ✅
- **Tipo**: Mensagem de contato (não echo)
- **Page ID (recipient)**: `108663328530265`
- **Canal encontrado**: ID 6
- **Inbox encontrado**: ID 11
- **Account ID**: 1

### 6. **Lock Distribuído** ✅
- **Lock Key**: `FB_MESSAGE_CREATE_LOCK::25865274353075858::108663328530265`
- **Status**: Adquirido na tentativa 2
- **Propósito**: Prevenir processamento duplicado

### 7. **Processamento da Mensagem** ✅
- **Inbox ID**: 11
- **Sender ID**: `25865274353075858`
- **ContactInbox ID**: 8 (já existente)
- **Contact ID**: 6 (já existente)
- **Tipo**: Mensagem de texto

### 8. **Criação da Mensagem via MessageBuilder** ❌ **FALHOU**
- **ContactInbox ID**: 8
- **Contact ID**: 6
- **Erro**: 
  ```
  SQLSTATE[23502]: Not null violation: 7 ERROR:  
  null value in column "contact_inbox_id" of relation "conversations" 
  violates not-null constraint
  ```

### 9. **Análise do Erro** 🔍

**Problema Identificado:**
- O código estava usando `new Conversation()` + `forceFill()` + `save()`
- O `contact_inbox_id` estava presente nos parâmetros, mas não estava sendo salvo no banco
- O SQL mostra que o campo foi enviado como `null`:
  ```sql
  insert into "conversations" 
  (account_id, inbox_id, contact_id, display_id, status, additional_attributes, updated_at, created_at) 
  values (1, 11, 6, 9, 0, [], 2025-11-25 19:57:53, 2025-11-25 19:57:53)
  ```
  - **Nota**: `contact_inbox_id` não aparece no SQL, indicando que foi removido antes do insert

**Causa Raiz:**
- O método `forceFill()` pode não estar funcionando corretamente com relacionamentos
- O modelo `Conversation` pode ter algum mutator/accessor que está interferindo
- A forma correta (usada no InstagramMessageBuilder) é `Conversation::create($params)` diretamente

### 10. **Correção Aplicada** ✅
- **Mudança**: Substituído `new Conversation()` + `forceFill()` + `save()` por `Conversation::create($params)`
- **Motivo**: Seguir o mesmo padrão do `InstagramMessageBuilder` que funciona corretamente
- **Arquivo**: `app/Builders/Messages/FacebookMessageBuilder.php` linha ~413

## 📊 Estatísticas da Mensagem

| Item | Valor |
|------|-------|
| **Sender ID** | 25865274353075858 |
| **Recipient ID** | 108663328530265 |
| **Message ID** | m_EeZ7UmlpCFE2gmtZhLsA4KHhSvn4fJqPtDK81ZUL1t-fUmNlvSEmDGTYkYqfYRNkI2YFwXS8YT-UR7AIIt5Frg |
| **Conteúdo** | "Olá" |
| **Channel ID** | 6 |
| **Inbox ID** | 11 |
| **Account ID** | 1 |
| **ContactInbox ID** | 8 |
| **Contact ID** | 6 |
| **Status Final** | ❌ Falhou ao criar conversa |

## 🔄 Segunda Tentativa (20:05:12)

Uma segunda mensagem foi recebida com o mesmo conteúdo ("Olá") e apresentou **exatamente o mesmo erro**, confirmando que o problema era sistemático e não relacionado a dados específicos da mensagem.

## ✅ Solução Implementada

1. **Correção no FacebookMessageBuilder**:
   - Mudado de `new Conversation()` + `forceFill()` + `save()` para `Conversation::create($params)`
   - Isso garante que todos os campos sejam incluídos corretamente no insert

2. **Validações Mantidas**:
   - Verificação de `contact_inbox_id` antes de criar
   - Logs detalhados para debug
   - Tratamento de erros

## 🧪 Próximos Passos para Teste

1. Enviar uma nova mensagem do Facebook
2. Verificar os logs para confirmar que a conversa é criada corretamente
3. Confirmar que a mensagem aparece no sistema
4. Verificar que o contato não aparece mais como "unknown" (se o perfil foi buscado com sucesso)

## 📝 Logs Relevantes para Monitorar

- `[FACEBOOK WEBHOOK]` - Recebimento do webhook
- `[FACEBOOK JOB]` - Processamento do job
- `[FACEBOOK MESSAGE PARSER]` - Parsing do payload
- `[FACEBOOK]` - Processamento geral
- `[FACEBOOK MESSAGE BUILDER]` - Criação da mensagem e conversa

