# Remoção do Campo `identifier` - Resumo das Alterações

## 🎯 Objetivo

Remover completamente o campo `identifier` antigo e usar apenas `identifier_facebook` e `identifier_instagram` separadamente.

## ✅ Alterações Implementadas

### 1. **Migration para Remover Campo**

**Arquivo:** `database/migrations/2025_11_25_000003_remove_identifier_from_contacts_table.php`

- Remove o índice `['account_id', 'identifier']`
- Remove a coluna `identifier` da tabela `contacts`

### 2. **Model Contact**

**Arquivo:** `app/Models/Contact.php`

- Removido `identifier` do `$fillable`
- Mantém apenas `identifier_facebook` e `identifier_instagram`

### 3. **ContactInboxWithContactBuilder**

**Arquivo:** `app/Builders/ContactInboxWithContactBuilder.php`

- Removida busca por `identifier` (código legacy)
- Removido `identifier` do `createContact()`
- Busca apenas por `identifier_facebook` e `identifier_instagram`

### 4. **FacebookChannel**

**Arquivo:** `app/Models/Channel/FacebookChannel.php`

- Removido `identifier` do array de atributos
- Usa apenas `identifier_facebook`

### 5. **InstagramChannel**

**Arquivo:** `app/Models/Channel/InstagramChannel.php`

- Removido `identifier` do array de atributos
- Usa apenas `identifier_instagram`

### 6. **Instagram IncomingMessageService**

**Arquivo:** `app/Services/Instagram/IncomingMessageService.php`

- Removida busca por `identifier` (código legacy)
- Removido `identifier` do `createContact()`
- Usa apenas `identifier_instagram`

### 7. **ContactsController**

**Arquivo:** `app/Http/Controllers/Api/V1/Accounts/ContactsController.php`

- Método `instagram()` agora busca por `whereNotNull('identifier_instagram')`
- Busca atualizada para usar `identifier_instagram` ao invés de `identifier`
- Respostas da API retornam `identifier_instagram` ao invés de `identifier`

## 📋 Ordem de Execução das Migrations

1. **Primeiro:** `2025_11_25_000001_add_identifier_facebook_and_identifier_instagram_to_contacts_table.php`
   - Adiciona os novos campos

2. **Segundo:** `2025_11_25_000002_migrate_existing_contact_identifiers.php`
   - Migra dados do campo `identifier` para os novos campos

3. **Terceiro:** `2025_11_25_000003_remove_identifier_from_contacts_table.php`
   - Remove o campo `identifier` antigo

## 🚀 Como Executar

```bash
# Executa todas as migrations na ordem correta
php artisan migrate

# Ou execute manualmente a migração de dados primeiro
php artisan contacts:migrate-identifiers
php artisan migrate
```

## 📊 Estrutura Final do Contact

Após todas as migrations, o objeto Contact terá:

```json
{
  "id": 1,
  "account_id": 1,
  "name": "Nome do Contato",
  "email": null,
  "phone_number": null,
  "identifier_facebook": "25865274353075858",  // Se for do Facebook
  "identifier_instagram": "2738857219788522",   // Se for do Instagram
  "avatar_url": null,
  "custom_attributes": [],
  "additional_attributes": {},
  "last_activity_at": null,
  "created_at": "2025-11-25T00:00:00.000000Z",
  "updated_at": "2025-11-25T00:00:00.000000Z"
}
```

**Sem o campo `identifier`!**

## ⚠️ Importante

- **Execute as migrations na ordem correta**
- **A migration de dados deve ser executada ANTES de remover o campo**
- **Após remover o campo, não será possível reverter sem perder dados**
- **O comando `contacts:migrate-identifiers` ainda funciona para migrar dados antes de remover o campo**

## ✅ Benefícios

- ✅ Código mais limpo e específico
- ✅ Um contato pode ter tanto `identifier_facebook` quanto `identifier_instagram`
- ✅ Facilita mesclar contatos no futuro
- ✅ Estrutura mais clara e organizada

