# Script para limpar cache e reiniciar containers após mudanças no código
# Uso: .\refresh-after-changes.ps1

Write-Host "🔄 Limpando cache e reiniciando containers..." -ForegroundColor Cyan

# Verificar se estamos no diretório correto
if (-not (Test-Path "docker-compose-dev.yml")) {
    Write-Host "❌ Execute este script da pasta docker/" -ForegroundColor Red
    exit 1
}

# 1. Limpar cache do Laravel
Write-Host "`n📦 Limpando cache do Laravel..." -ForegroundColor Yellow
docker-compose -f docker-compose-dev.yml exec -T backend sh -c "php artisan config:clear && php artisan cache:clear && php artisan route:clear && php artisan view:clear" 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Cache do Laravel limpo" -ForegroundColor Green
} else {
    Write-Host "⚠️  Erro ao limpar cache (container pode não estar rodando)" -ForegroundColor Yellow
}

# 2. Reiniciar frontend (para pegar mudanças no código)
Write-Host "`n🔄 Reiniciando frontend..." -ForegroundColor Yellow
docker-compose -f docker-compose-dev.yml restart frontend 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Frontend reiniciado" -ForegroundColor Green
} else {
    Write-Host "⚠️  Erro ao reiniciar frontend" -ForegroundColor Yellow
}

# 3. Reiniciar backend (opcional, geralmente não precisa)
$restartBackend = Read-Host "`n❓ Reiniciar backend também? (s/N)"
if ($restartBackend -eq "s" -or $restartBackend -eq "S") {
    Write-Host "🔄 Reiniciando backend..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml restart backend backend-queue 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✅ Backend reiniciado" -ForegroundColor Green
    }
}

Write-Host "`n✅ Concluído!" -ForegroundColor Green
Write-Host "`n💡 Dica: Se ainda não ver as mudanças, faça hard refresh no navegador (Ctrl+Shift+R)" -ForegroundColor Cyan

