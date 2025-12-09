# Fluxo Completo da Requisição - Debug

## 🔍 Fluxo da Requisição PUT /api/v1/accounts/1/inboxes/3

### 1. **Entrada da Requisição**
```
PUT https://1bdbe6fe0dc6.ngrok-free.app/api/v1/accounts/1/inboxes/3
Headers:
  - Authorization: Bearer {token}
  - Content-Type: application/json
```

### 2. **Middleware: auth:sanctum**
- Verifica o token Bearer
- Autentica o usuário
- Define `$request->user()`

**Log esperado:** (interno do Laravel Sanctum)

### 3. **Middleware: EnsureAccountAccess** ⭐ PONTO CRÍTICO 1
**Arquivo:** `app/Http/Middleware/EnsureAccountAccess.php`

**O que faz:**
1. Extrai `account_id` da rota: `$request->route('account_id')`
2. Verifica se usuário tem acesso à account
3. Busca a account: `Account::find($accountId)`
4. **Define Current::account()**: `Current::setAccount($account)`

**Logs adicionados:**
```
[ENSURE_ACCOUNT_ACCESS] ========== START ==========
[ENSURE_ACCOUNT_ACCESS] Definindo Current::account()
[ENSURE_ACCOUNT_ACCESS] Current::account() após definir
[ENSURE_ACCOUNT_ACCESS] ========== SUCCESS ==========
[ENSURE_ACCOUNT_ACCESS] Calling next middleware/controller
```

**Verificar nos logs:**
- ✅ `account_id_param` deve ser `1`
- ✅ `account_id` após definir deve ser `1`
- ✅ `current_account_verified` deve ser `true`

### 4. **Roteamento**
**Arquivo:** `routes/api.php`

**Rota correspondente:**
```php
Route::put('inboxes/{inbox_id}', [InboxesController::class, 'update'])
    ->where('inbox_id', '[0-9]+')
    ->name('inboxes.update.alias');
```

**Parâmetros capturados:**
- `account_id` = `1` (da URL)
- `inbox_id` = `3` (da URL)

### 5. **Controller: InboxesController::update()** ⭐ PONTO CRÍTICO 2
**Arquivo:** `app/Http/Controllers/Api/V1/Accounts/InboxesController.php`

**Etapas:**

#### 5.1. Log inicial
```
[INBOXES] ========== UPDATE REQUEST START ==========
```
**Verificar:**
- ✅ `inbox_id_from_param` = `3`
- ✅ `inbox_id_from_route` = `3`
- ✅ `route_params` contém `account_id` e `inbox_id`

#### 5.2. Verifica Current::account()
```
[INBOXES] update - Current::account() check
```
**Verificar:**
- ✅ `account_found` = `true`
- ✅ `account_id` = `1`

#### 5.3. Busca o inbox COM global scope
```php
$inboxModel = \App\Models\Inbox::where('id', $inbox_id)->first();
```

**Neste momento, o global scope `HasAccountScope` é aplicado automaticamente!**

### 6. **Global Scope: HasAccountScope** ⭐ PONTO CRÍTICO 3
**Arquivo:** `app/Models/Concerns/HasAccountScope.php`

**O que faz:**
1. Verifica `Current::account()`
2. Se account existe, aplica: `where('inboxes.account_id', $account->id)`
3. Se account NÃO existe, aplica: `whereRaw('1 = 0')` (bloqueia query)

**Logs adicionados:**
```
[HasAccountScope] Aplicando scope em Inbox
[HasAccountScope] Filtro aplicado
```

**Verificar nos logs:**
- ✅ `account_found` = `true`
- ✅ `account_id` = `1`
- ✅ `where_clause` = `inboxes.account_id = 1`

**⚠️ PROBLEMA POTENCIAL:**
Se `Current::account()` for `null` neste momento, a query será bloqueada!

### 7. **Query SQL Executada**

**Com scope funcionando:**
```sql
SELECT * FROM inboxes 
WHERE inboxes.id = 3 
AND inboxes.account_id = 1
LIMIT 1
```

**Se scope não funcionar (Current::account() = null):**
```sql
SELECT * FROM inboxes 
WHERE inboxes.id = 3 
AND 1 = 0  -- BLOQUEADO!
LIMIT 1
```

### 8. **Resultado da Busca**

#### ✅ Se encontrou:
```
[INBOXES] update - Inbox encontrado e validado
```

#### ❌ Se NÃO encontrou:
```
[INBOXES] update - Inbox NÃO encontrado COM scope, iniciando debug...
[INBOXES] update - Busca SEM scope (debug)
[INBOXES] update - Todos os inboxes da account
[INBOXES] update - ========== INBOX NÃO ENCONTRADO ==========
```

**Diagnóstico nos logs:**
- `inbox_exists_globally`: Se o inbox existe no banco (sem scope)
- `inbox_account_id`: Qual account o inbox pertence
- `inbox_in_same_account`: Se o inbox está na mesma account
- `inbox_in_other_account`: Se o inbox está em outra account
- `diagnosis`: Diagnóstico do problema

### 9. **Resposta de Erro Melhorada**

**Antes:**
```json
{
  "error": "Inbox não encontrado",
  "message": "O inbox especificado não foi encontrado nesta conta."
}
```

**Agora:**
```json
{
  "error": "Inbox não encontrado",
  "message": "Inbox 3 não encontrado na account 1",
  "inbox_id": 3,
  "account_id": 1,
  "inbox_exists": true,
  "inbox_account_id": 1
}
```

**Se inbox está em outra account:**
```json
{
  "error": "Inbox não encontrado",
  "message": "Inbox 3 não encontrado na account 1. O inbox existe mas pertence à account 2",
  "inbox_id": 3,
  "account_id": 1,
  "inbox_exists": true,
  "inbox_account_id": 2
}
```

**Se problema de scope:**
```json
{
  "error": "Inbox não encontrado",
  "message": "Inbox 3 não encontrado na account 1. O inbox existe na account mas não foi encontrado (possível problema de scope)",
  "inbox_id": 3,
  "account_id": 1,
  "inbox_exists": true,
  "inbox_account_id": 1
}
```

---

## 🔍 Como Debugar

### 1. Verifique os logs na ordem:

```bash
# 1. Middleware
grep "ENSURE_ACCOUNT_ACCESS" storage/logs/laravel.log

# 2. Global Scope
grep "HasAccountScope" storage/logs/laravel.log

# 3. Controller
grep "INBOXES.*update" storage/logs/laravel.log
```

### 2. Pontos críticos a verificar:

#### ✅ Current::account() está definido?
```
[ENSURE_ACCOUNT_ACCESS] Current::account() após definir
  account_id: 1  ← DEVE SER 1
```

#### ✅ Global Scope está aplicando o filtro?
```
[HasAccountScope] Filtro aplicado
  account_id: 1  ← DEVE SER 1
  where_clause: "inboxes.account_id = 1"
```

#### ✅ Inbox existe no banco?
```
[INBOXES] update - Busca SEM scope (debug)
  inbox_exists_globally: true  ← DEVE SER true
  inbox_account_id: 1  ← DEVE SER 1
```

#### ✅ Inbox está na mesma account?
```
[INBOXES] update - ========== INBOX NÃO ENCONTRADO ==========
  inbox_in_same_account: true  ← Se true, é problema de scope!
  diagnosis: "PROBLEMA DE SCOPE: Inbox existe na account mas não foi encontrado com scope"
```

### 3. Possíveis Problemas:

#### Problema 1: Current::account() é null no scope
**Sintoma:**
```
[HasAccountScope] Account não definida no contexto - BLOQUEANDO QUERY
```

**Causa:** O `Current::account()` foi perdido entre o middleware e o scope.

**Solução:** Verificar se há algum middleware ou código que está resetando `Current::account()`.

#### Problema 2: Inbox existe mas não é encontrado
**Sintoma:**
```
inbox_exists_globally: true
inbox_in_same_account: true
inbox_found: false
```

**Causa:** O global scope não está funcionando corretamente.

**Solução:** Verificar se `Current::account()` está definido quando o scope é aplicado.

#### Problema 3: Inbox está em outra account
**Sintoma:**
```
inbox_exists_globally: true
inbox_in_other_account: true
inbox_account_id: 2
requested_account_id: 1
```

**Causa:** O inbox realmente não pertence à account solicitada.

**Solução:** Verificar se o `inbox_id` e `account_id` estão corretos na requisição.

---

## 📊 Exemplo de Log Completo (Sucesso)

```
[ENSURE_ACCOUNT_ACCESS] ========== START ==========
  account_id_param: 1
[ENSURE_ACCOUNT_ACCESS] Definindo Current::account()
  account_id: 1
[ENSURE_ACCOUNT_ACCESS] Current::account() após definir
  account_id: 1
[ENSURE_ACCOUNT_ACCESS] ========== SUCCESS ==========

[INBOXES] ========== UPDATE REQUEST START ==========
  inbox_id_from_param: 3
  account_id_from_route: 1

[HasAccountScope] Aplicando scope em Inbox
  account_id: 1
[HasAccountScope] Filtro aplicado
  where_clause: "inboxes.account_id = 1"

[INBOXES] update - Resultado da busca COM scope
  inbox_found: true
  inbox_id: 3
  inbox_account_id: 1

[INBOXES] update - Inbox encontrado e validado
```

---

## 📊 Exemplo de Log Completo (Erro - Problema de Scope)

```
[ENSURE_ACCOUNT_ACCESS] ========== START ==========
  account_id_param: 1
[ENSURE_ACCOUNT_ACCESS] Definindo Current::account()
  account_id: 1
[ENSURE_ACCOUNT_ACCESS] ========== SUCCESS ==========

[INBOXES] ========== UPDATE REQUEST START ==========
  inbox_id_from_param: 3

[HasAccountScope] Aplicando scope em Inbox
  account_found: false  ← PROBLEMA!
  account_id: null

[HasAccountScope] Account não definida no contexto - BLOQUEANDO QUERY

[INBOXES] update - Resultado da busca COM scope
  inbox_found: false

[INBOXES] update - Busca SEM scope (debug)
  inbox_exists_globally: true
  inbox_account_id: 1

[INBOXES] update - ========== INBOX NÃO ENCONTRADO ==========
  diagnosis: "PROBLEMA DE SCOPE: Inbox existe na account mas não foi encontrado com scope"
```

---

## 🚀 Próximos Passos

1. **Execute o curl novamente**
2. **Verifique os logs** na ordem mostrada acima
3. **Identifique o problema** usando os diagnósticos
4. **Compartilhe os logs** para análise mais profunda

Os logs agora mostram exatamente onde o problema está ocorrendo!

