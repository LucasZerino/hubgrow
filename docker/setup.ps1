# Script de setup inicial para desenvolvimento (PowerShell)
# Executa migrações e seeders no backend

Write-Host "🔧 Configurando ambiente de desenvolvimento..." -ForegroundColor Cyan

# Verificar se os containers estão rodando
$backendStatus = docker-compose -f docker-compose-dev.yml ps backend 2>&1
if ($backendStatus -notmatch "Up") {
    Write-Host "❌ Backend container não está rodando. Inicie com: docker-compose -f docker-compose-dev.yml up -d" -ForegroundColor Red
    exit 1
}

Write-Host "📦 Instalando dependências do backend..." -ForegroundColor Yellow
docker-compose -f docker-compose-dev.yml exec -T backend composer install --no-interaction

Write-Host "🔑 Gerando chave da aplicação..." -ForegroundColor Yellow
docker-compose -f docker-compose-dev.yml exec -T backend php artisan key:generate --force

Write-Host "🗄️  Rodando migrações..." -ForegroundColor Yellow
docker-compose -f docker-compose-dev.yml exec -T backend php artisan migrate --force

Write-Host "🌱 Rodando seeders..." -ForegroundColor Yellow
docker-compose -f docker-compose-dev.yml exec -T backend php artisan db:seed --class=SuperAdminSeeder

Write-Host "✅ Setup completo!" -ForegroundColor Green
Write-Host ""
Write-Host "🌐 Acesse:" -ForegroundColor Cyan
Write-Host "   - Frontend: http://localhost:5173"
Write-Host "   - Backend:  http://localhost:8000"
Write-Host "   - MinIO:    http://localhost:9001"
Write-Host "   - MailHog:  http://localhost:8025"

