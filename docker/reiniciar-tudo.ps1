# Script para reiniciar todos os containers após mudanças
# Execute da raiz do projeto: .\docker\reiniciar-tudo.ps1

Write-Host "🔄 Reiniciando containers..." -ForegroundColor Cyan

# Verificar se estamos no diretório correto
$dockerComposePath = Join-Path $PSScriptRoot "docker-compose-dev.yml"
if (-not (Test-Path $dockerComposePath)) {
    Write-Host "❌ Execute este script da raiz do projeto: .\docker\reiniciar-tudo.ps1" -ForegroundColor Red
    exit 1
}

# Mudar para o diretório docker
Push-Location $PSScriptRoot

try {
    Write-Host "`n📦 Parando containers..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml down

    Write-Host "`n🚀 Iniciando containers..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml up -d

    Write-Host "`n⏳ Aguardando containers iniciarem (30 segundos)..." -ForegroundColor Yellow
    Start-Sleep -Seconds 30

    Write-Host "`n📊 Status dos containers:" -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml ps

    Write-Host "`n🔍 Verificando Reverb..." -ForegroundColor Yellow
    $reverbStatus = docker-compose -f docker-compose-dev.yml ps reverb | Select-String -Pattern "Up|Exit"
    if ($reverbStatus) {
        Write-Host "✅ Reverb: $reverbStatus" -ForegroundColor Green
    } else {
        Write-Host "⚠️  Reverb não encontrado ou não está rodando" -ForegroundColor Yellow
    }

    Write-Host "`n📋 Logs do Reverb (últimas 10 linhas):" -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml logs reverb --tail 10

    Write-Host "`n✅ Concluído!" -ForegroundColor Green
    Write-Host "`n💡 Próximos passos:" -ForegroundColor Cyan
    Write-Host "   1. Verifique se Reverb está rodando: docker-compose -f docker-compose-dev.yml ps reverb" -ForegroundColor White
    Write-Host "   2. Verifique logs: docker-compose -f docker-compose-dev.yml logs -f reverb" -ForegroundColor White
    Write-Host "   3. No navegador, faça hard refresh (Ctrl+Shift+R)" -ForegroundColor White
    Write-Host "   4. Verifique o console do navegador - deve conectar ao WebSocket sem erros" -ForegroundColor White

} finally {
    Pop-Location
}

