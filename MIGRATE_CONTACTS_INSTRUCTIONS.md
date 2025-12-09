# Instruções para Migrar Contatos Existentes

## 🎯 Objetivo

Migrar contatos existentes do formato antigo (`identifier`) para os novos campos (`identifier_facebook`, `identifier_instagram`).

## 📋 Contatos que Serão Migrados

Baseado nos contatos fornecidos:

1. **Contato ID 2** (Instagram)
   - `identifier`: `"instagram_2738857219788522"`
   - → `identifier_instagram`: `"2738857219788522"`

2. **Contato ID 1** (Instagram)
   - `identifier`: `"instagram_821655940503705"`
   - → `identifier_instagram`: `"821655940503705"`

3. **Contato ID 6** (Facebook)
   - `identifier`: `"facebook_25865274353075858"`
   - → `identifier_facebook`: `"25865274353075858"`

## 🚀 Como Executar

### Opção 1: Via Migration (Automático)

Quando executar `php artisan migrate`, a migration `2025_11_25_000002_migrate_existing_contact_identifiers.php` será executada automaticamente:

```bash
php artisan migrate
```

### Opção 2: Via Comando Artisan (Manual)

Execute o comando criado especificamente para isso:

```bash
# Modo DRY-RUN (apenas visualiza, não altera)
php artisan contacts:migrate-identifiers --dry-run

# Executa a migração
php artisan contacts:migrate-identifiers
```

## ✅ O Que Será Feito

### Para Contatos do Facebook:
- Busca contatos com `identifier` no formato `"facebook_XXXXX"`
- Extrai o ID: `"facebook_25865274353075858"` → `"25865274353075858"`
- Atualiza `identifier_facebook` com o ID extraído
- **Mantém** o campo `identifier` original (compatibilidade)

### Para Contatos do Instagram:
- Busca contatos com `identifier` no formato `"instagram_XXXXX"`
- Extrai o ID: `"instagram_2738857219788522"` → `"2738857219788522"`
- Atualiza `identifier_instagram` com o ID extraído
- **Mantém** o campo `identifier` original (compatibilidade)

## 🔍 Validações

A migration:
- ✅ Só migra contatos que ainda não têm `identifier_facebook` ou `identifier_instagram`
- ✅ Valida que o ID extraído não está vazio
- ✅ Mantém o campo `identifier` original para compatibilidade
- ✅ Atualiza `updated_at` automaticamente
- ✅ Loga cada contato migrado

## 📊 Resultado Esperado

Após a migração, os contatos terão:

### Contato ID 2 (Instagram):
```json
{
  "id": 2,
  "identifier": "instagram_2738857219788522",  // Mantido
  "identifier_instagram": "2738857219788522",  // Novo
  "identifier_facebook": null
}
```

### Contato ID 1 (Instagram):
```json
{
  "id": 1,
  "identifier": "instagram_821655940503705",  // Mantido
  "identifier_instagram": "821655940503705",  // Novo
  "identifier_facebook": null
}
```

### Contato ID 6 (Facebook):
```json
{
  "id": 6,
  "identifier": "facebook_25865274353075858",  // Mantido
  "identifier_facebook": "25865274353075858",  // Novo
  "identifier_instagram": null
}
```

## ⚠️ Importante

- O campo `identifier` **NÃO é removido** - mantido para compatibilidade
- A migration é **idempotente** - pode ser executada múltiplas vezes sem problemas
- Contatos que já têm `identifier_facebook` ou `identifier_instagram` **não são alterados**

## 🧪 Testar Antes

Recomendado executar em modo DRY-RUN primeiro:

```bash
php artisan contacts:migrate-identifiers --dry-run
```

Isso mostrará quais contatos seriam migrados sem fazer alterações no banco.

## 📝 Logs

A migration registra logs detalhados:
- Cada contato migrado
- Contadores de Facebook e Instagram
- Total de contatos migrados

Os logs aparecem em:
- Console (se executar via comando artisan)
- `storage/logs/laravel.log` (se executar via migration)

