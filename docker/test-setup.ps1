# Script PowerShell para configurar e executar testes
# Uso: .\test-setup.ps1 [comando] [compose-file]
# Comandos: up, down, test, migrate, shell
# Compose files: docker-compose-dev.yml (padrão) ou docker-compose.yml

param(
    [string]$Command = "up",
    [string]$ComposeFile = "docker-compose-dev.yml"
)

$ComposeCmd = "docker-compose -f $ComposeFile"

switch ($Command) {
    "up" {
        Write-Host "🚀 Iniciando serviços de teste no $ComposeFile..." -ForegroundColor Green
        & docker-compose -f $ComposeFile up -d postgres_test redis_test
        
        Write-Host "⏳ Aguardando serviços ficarem prontos..." -ForegroundColor Yellow
        Start-Sleep -Seconds 5
        
        Write-Host "📦 Instalando dependências (localmente)..." -ForegroundColor Cyan
        Push-Location ../backend
        composer install --no-interaction 2>$null
        
        Write-Host "🔑 Gerando chave da aplicação..." -ForegroundColor Cyan
        php artisan key:generate --env=testing 2>$null
        
        Write-Host "🗄️  Executando migrations no banco de teste..." -ForegroundColor Cyan
        php artisan migrate --env=testing --force 2>$null
        Pop-Location
        
        Write-Host "✅ Ambiente de testes pronto!" -ForegroundColor Green
        Write-Host ""
        Write-Host "Para executar testes (localmente):" -ForegroundColor Yellow
        Write-Host "  cd backend && php artisan test" -ForegroundColor White
        Write-Host ""
        Write-Host "Ou use:" -ForegroundColor Yellow
        Write-Host "  .\test-setup.ps1 test $ComposeFile" -ForegroundColor White
    }
    
    "down" {
        Write-Host "🛑 Parando serviços de teste..." -ForegroundColor Yellow
        & docker-compose -f $ComposeFile stop postgres_test redis_test
        Write-Host "✅ Serviços de teste parados!" -ForegroundColor Green
    }
    
    "test" {
        Write-Host "🧪 Executando testes (localmente)..." -ForegroundColor Cyan
        Push-Location ../backend
        php artisan test
        Pop-Location
    }
    
    "migrate" {
        Write-Host "🗄️  Executando migrations (localmente)..." -ForegroundColor Cyan
        Push-Location ../backend
        php artisan migrate:fresh --env=testing --force
        Pop-Location
    }
    
    "shell" {
        Write-Host "🐚 Acessando PostgreSQL de teste..." -ForegroundColor Cyan
        & docker-compose -f $ComposeFile exec postgres_test psql -U postgres -d hubphp_test
    }
    
    "clean" {
        Write-Host "🧹 Limpando volumes de teste..." -ForegroundColor Yellow
        & docker-compose -f $ComposeFile stop postgres_test redis_test
        $VolumePrefix = $ComposeFile.Replace(".yml", "").Replace("docker-compose-", "")
        docker volume rm "${VolumePrefix}_postgres_test_data" "${VolumePrefix}_redis_test_data" 2>$null
        Write-Host "✅ Volumes de teste removidos!" -ForegroundColor Green
    }
    
    default {
        Write-Host "Uso: .\test-setup.ps1 [comando] [compose-file]" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "Comandos disponíveis:" -ForegroundColor White
        Write-Host "  up      - Inicia serviços de teste (postgres_test, redis_test)" -ForegroundColor Gray
        Write-Host "  down    - Para serviços de teste" -ForegroundColor Gray
        Write-Host "  test    - Executa testes PHPUnit (localmente)" -ForegroundColor Gray
        Write-Host "  migrate - Executa migrations no banco de teste (localmente)" -ForegroundColor Gray
        Write-Host "  shell   - Acessa PostgreSQL de teste" -ForegroundColor Gray
        Write-Host "  clean   - Remove volumes de teste (limpa dados)" -ForegroundColor Gray
        Write-Host ""
        Write-Host "Compose files:" -ForegroundColor White
        Write-Host "  docker-compose-dev.yml (padrão)" -ForegroundColor Gray
        Write-Host "  docker-compose.yml" -ForegroundColor Gray
        exit 1
    }
}

