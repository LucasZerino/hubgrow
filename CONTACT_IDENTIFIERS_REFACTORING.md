# Refatoração de Identificadores de Contatos

## 🎯 Objetivo

Refatorar o sistema de identificação de contatos para suportar múltiplos identificadores (Instagram e Facebook) em um único contato, permitindo:
- Ter um contato único que pode receber mensagens tanto do Instagram quanto do Facebook
- Mesclar contatos depois
- Manter compatibilidade com código antigo

## ✅ Alterações Implementadas

### 1. **Migration de Banco de Dados**

**Arquivo:** `database/migrations/2025_11_25_000001_add_identifier_facebook_and_identifier_instagram_to_contacts_table.php`

- Adiciona campos `identifier_facebook` e `identifier_instagram` na tabela `contacts`
- Cria índices para busca rápida
- Mantém o campo `identifier` para compatibilidade

### 2. **Migration de Dados**

**Arquivo:** `database/migrations/2025_11_25_000002_migrate_existing_contact_identifiers.php`

- Migra identificadores existentes do formato antigo (`identifier`) para os novos campos
- Extrai IDs do Facebook de `facebook_123456` → `identifier_facebook: 123456`
- Extrai IDs do Instagram de `instagram_123456` → `identifier_instagram: 123456`

### 3. **Model Contact**

**Arquivo:** `app/Models/Contact.php`

- Adiciona `identifier_facebook` e `identifier_instagram` ao `$fillable`
- Mantém `identifier` para compatibilidade

### 4. **ContactInboxWithContactBuilder**

**Arquivo:** `app/Builders/ContactInboxWithContactBuilder.php`

**Melhorias:**
- Busca por `identifier_facebook` (prioridade para Facebook)
- Busca por `identifier_instagram` (prioridade para Instagram)
- Busca por `identifier` (compatibilidade com código antigo)
- Busca por `email` e `phone_number`
- Busca por `source_id` em outros canais
- Atualiza identificadores quando encontra contato existente
- Permite que um contato tenha tanto `identifier_facebook` quanto `identifier_instagram`

**Novos Métodos:**
- `updateContactIdentifiers()` - Atualiza identificadores do contato se necessário
- `isFacebookChannel()` - Verifica se é canal Facebook
- `findContactByFacebookSourceId()` - Busca contato por Facebook source_id em outros canais

### 5. **FacebookChannel**

**Arquivo:** `app/Models/Channel/FacebookChannel.php`

- Atualiza `createContactInbox()` para usar `identifier_facebook`
- Mantém `identifier` para compatibilidade

### 6. **InstagramChannel**

**Arquivo:** `app/Models/Channel/InstagramChannel.php`

- Atualiza `createContactInbox()` para usar `identifier_instagram`
- Mantém `identifier` para compatibilidade

### 7. **Instagram IncomingMessageService**

**Arquivo:** `app/Services/Instagram/IncomingMessageService.php`

- Atualiza `findExistingContact()` para buscar por `identifier_instagram` primeiro
- Atualiza `createContact()` para usar `identifier_instagram`
- Migra automaticamente contatos antigos que usam `identifier`

## 📊 Fluxo de Busca de Contatos

### Ordem de Prioridade:

1. **identifier_facebook** (se fornecido)
2. **identifier_instagram** (se fornecido)
3. **identifier** (compatibilidade com código antigo)
4. **email**
5. **phone_number**
6. **source_id em outros canais** (Instagram/Facebook)

### Exemplo:

**Cenário 1: Contato existe apenas no Facebook**
- Busca por `identifier_facebook` → Encontra
- Cria `ContactInbox` para Instagram
- Contato agora tem ambos os identificadores

**Cenário 2: Contato existe apenas no Instagram**
- Busca por `identifier_instagram` → Encontra
- Cria `ContactInbox` para Facebook
- Contato agora tem ambos os identificadores

**Cenário 3: Contato não existe**
- Cria novo contato com `identifier_facebook` ou `identifier_instagram`
- Mantém `identifier` para compatibilidade

## 🔄 Compatibilidade

### Código Antigo:
- Continua funcionando com `identifier`
- Busca por `identifier` ainda funciona
- Criação com `identifier` ainda funciona

### Código Novo:
- Usa `identifier_facebook` e `identifier_instagram`
- Busca prioriza os novos campos
- Atualiza contatos antigos automaticamente

## 📝 Próximos Passos

1. ✅ **Migration criada** - Adiciona campos ao banco
2. ✅ **Migration de dados** - Migra identificadores existentes
3. ✅ **Model atualizado** - Suporta novos campos
4. ✅ **Builders atualizados** - Usam novos campos
5. ✅ **Canais atualizados** - Facebook e Instagram
6. ✅ **Serviços atualizados** - Instagram IncomingMessageService
7. ⏳ **Executar migrations** - `php artisan migrate`
8. ⏳ **Testar com mensagens reais** - Verificar se funciona
9. ⏳ **Criar funcionalidade de mesclar contatos** - Futuro

## 🎯 Benefícios

- ✅ **Um contato único** - Pode ter conversas no Instagram e Facebook
- ✅ **Mesclagem futura** - Base para mesclar contatos depois
- ✅ **Compatibilidade** - Código antigo continua funcionando
- ✅ **Busca eficiente** - Índices criados para busca rápida
- ✅ **Migração automática** - Contatos antigos são atualizados automaticamente

## ⚠️ Observações

- O campo `identifier` é mantido para compatibilidade
- Contatos antigos são migrados automaticamente
- Novos contatos usam os novos campos
- Busca prioriza os novos campos, mas ainda funciona com `identifier`

