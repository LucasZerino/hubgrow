# Script para analisar a jornada completa da mensagem
# Execute da raiz do projeto: .\docker\analisar-jornada-mensagem.ps1

Write-Host "🔍 Analisando jornada da mensagem..." -ForegroundColor Cyan

$dockerComposePath = Join-Path $PSScriptRoot "docker-compose-dev.yml"
if (-not (Test-Path $dockerComposePath)) {
    Write-Host "❌ Execute este script da raiz do projeto" -ForegroundColor Red
    exit 1
}

Push-Location $PSScriptRoot

try {
    Write-Host "`n📥 1. Verificando se webhook chegou no backend..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml logs backend --tail 100 | Select-String -Pattern "INSTAGRAM|WEBHOOK" -Context 1 | Select-Object -Last 20

    Write-Host "`n⚙️  2. Verificando se job foi processado na queue..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml logs backend-queue --tail 100 | Select-String -Pattern "INSTAGRAM|MESSAGE|IncomingMessageService" -Context 1 | Select-Object -Last 20

    Write-Host "`n💾 3. Verificando se mensagem foi criada no banco..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml exec -T backend php artisan tinker --execute="echo 'Total messages: ' . \App\Models\Message::count() . PHP_EOL; echo 'Last 5 messages:' . PHP_EOL; \App\Models\Message::latest()->take(5)->get(['id', 'content', 'message_type', 'conversation_id', 'created_at'])->each(function(\$m) { echo '  ID: ' . \$m->id . ' | Type: ' . \$m->message_type . ' | Content: ' . substr(\$m->content, 0, 50) . '... | Conv: ' . \$m->conversation_id . ' | Created: ' . \$m->created_at . PHP_EOL; });"

    Write-Host "`n📡 4. Verificando se evento MessageCreated foi disparado..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml logs backend --tail 200 | Select-String -Pattern "MESSAGE MODEL|MESSAGE CREATED EVENT" -Context 1 | Select-Object -Last 15

    Write-Host "`n🔌 5. Verificando se Reverb está rodando..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml ps reverb
    docker-compose -f docker-compose-dev.yml logs reverb --tail 20

    Write-Host "`n📢 6. Verificando se broadcast foi feito..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml logs backend --tail 200 | Select-String -Pattern "broadcast|Broadcasting" -Context 1 | Select-Object -Last 10

    Write-Host "`n❌ 7. Verificando erros..." -ForegroundColor Yellow
    docker-compose -f docker-compose-dev.yml logs backend --tail 200 | Select-String -Pattern "ERROR|Exception|Failed" -Context 2 | Select-Object -Last 10
    docker-compose -f docker-compose-dev.yml logs backend-queue --tail 200 | Select-String -Pattern "ERROR|Exception|Failed" -Context 2 | Select-Object -Last 10

    Write-Host "`n✅ Análise concluída!" -ForegroundColor Green
} catch {
    Write-Host "`n❌ Erro ao analisar: $_" -ForegroundColor Red
} finally {
    Pop-Location
}

