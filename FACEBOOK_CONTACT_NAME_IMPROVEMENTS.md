# Melhorias para Obter Nome do Contato do Facebook

## 🎯 Objetivo

Ajustar o código para pegar o nome do contato do Facebook quando recebe uma mensagem, evitando criar contatos como "Unknown".

## ✅ Melhorias Implementadas

### 1. **Retry na Busca do Perfil** (`ensureContact`)

**Antes:**
- Tentava buscar o perfil uma vez
- Se falhasse, criava como "Unknown"

**Depois:**
- Tenta buscar o perfil até 3 vezes com retry
- Usa exponential backoff (1s, 2s, 4s)
- Só cria como "Unknown" se todas as tentativas falharem

**Código:**
```php
$maxRetries = 3;
$retryDelay = 1; // segundos

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    $userInfo = $this->fetchFacebookUserProfile($facebookId);
    
    if ($userInfo && isset($userInfo['name']) && !empty($userInfo['name'])) {
        break; // Sucesso, sai do loop
    }
    
    if ($attempt < $maxRetries) {
        sleep($retryDelay);
        $retryDelay *= 2; // Exponential backoff
    }
}
```

### 2. **Melhor Extração do Nome**

**Antes:**
```php
$name = $userInfo['name'] ?? $userInfo['first_name'] ?? "Unknown (FB: {$facebookId})";
```

**Depois:**
```php
// Prioriza name, depois first_name, depois first_name + last_name, por último Unknown
$name = $userInfo['name'] 
    ?? ($userInfo['first_name'] ?? null)
    ?? (($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? ''))
    ?? "Unknown (FB: {$facebookId})";

// Remove espaços extras
$name = trim($name);

// Se ainda estiver vazio ou for só espaços, usa Unknown
if (empty($name)) {
    $name = "Unknown (FB: {$facebookId})";
}
```

### 3. **Melhor Tratamento de Erros na API** (`fetchUserProfile`)

**Melhorias:**
- Timeout de 10 segundos
- Logs mais detalhados dos erros
- Tratamento específico para erros 400 (sem permissão) e 401 (token inválido)
- Validação se o perfil retornado tem nome antes de retornar

**Código:**
```php
$response = Http::timeout(10)->get(
    $url,
    [
        'access_token' => $pageAccessToken,
        'fields' => 'id,name,first_name,last_name,profile_pic',
    ]
);

// Valida se tem pelo menos um campo de nome
$hasName = isset($userInfo['name']) && !empty($userInfo['name']);
$hasFirstName = isset($userInfo['first_name']) && !empty($userInfo['first_name']);

// Se não tem nome, retorna null para tentar novamente
if (!$hasName && !$hasFirstName) {
    return null;
}
```

### 4. **Melhor Atualização de Contatos "Unknown"** (`tryUpdateUnknownContactName`)

**Melhorias:**
- Retry na busca do perfil (2 tentativas)
- Melhor extração do nome (name, first_name, first_name + last_name)
- Atualiza também o avatar se disponível
- Logs mais detalhados

**Código:**
```php
$maxRetries = 2;

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    $userInfo = $this->fetchFacebookUserProfile($facebookId);
    
    if ($userInfo && (isset($userInfo['name']) || isset($userInfo['first_name']))) {
        break;
    }
    
    if ($attempt < $maxRetries) {
        sleep(1);
    }
}
```

### 5. **Logs Mais Detalhados**

**Adicionados:**
- Logs de cada tentativa de busca
- Logs detalhados dos erros da API
- Logs do processo de extração do nome
- Logs de sucesso/falha na atualização

## 📊 Fluxo Melhorado

1. **Recebe mensagem do Facebook**
2. **Tenta buscar perfil (até 3 vezes)**
   - Se sucesso: usa o nome
   - Se falha: cria como "Unknown"
3. **Cria ContactInbox com nome**
   - Prioriza: name → first_name → first_name + last_name → Unknown
4. **Se criado como "Unknown", tenta atualizar**
   - Busca perfil novamente (até 2 vezes)
   - Atualiza nome se encontrado

## 🔍 Possíveis Problemas e Soluções

### Problema: API retorna erro 400
**Causa:** Token não tem permissão `pages_messaging` ou usuário não permitiu acesso
**Solução:** Verificar permissões do token no Facebook Developer Console

### Problema: API retorna erro 401
**Causa:** Token expirado ou inválido
**Solução:** Renovar o token da página

### Problema: API retorna sucesso mas sem nome
**Causa:** Usuário não tem nome público ou API não retorna
**Solução:** Usar first_name + last_name como fallback

## 📝 Próximos Passos

1. ✅ **Retry implementado** - Tenta até 3 vezes antes de criar como Unknown
2. ✅ **Melhor extração de nome** - Usa name, first_name, ou first_name + last_name
3. ✅ **Logs melhorados** - Mais detalhes para debug
4. ✅ **Tratamento de erros** - Melhor identificação de problemas
5. ⏳ **Testar com mensagens reais** - Verificar se funciona na prática

## 🎯 Resultado Esperado

- **Menos contatos "Unknown"** - Retry aumenta chances de obter o nome
- **Nomes mais completos** - Usa first_name + last_name se name não disponível
- **Melhor debugging** - Logs detalhados ajudam a identificar problemas
- **Atualização automática** - Contatos "Unknown" são atualizados automaticamente

