#!/bin/bash

# Script para configurar e executar testes
# Uso: ./test-setup.sh [comando] [compose-file]
# Comandos: up, down, test, migrate, shell
# Compose files: docker-compose-dev.yml (padrão) ou docker-compose.yml

set -e

COMPOSE_FILE="${2:-docker-compose-dev.yml}"
COMPOSE_CMD="docker-compose -f $COMPOSE_FILE"

case "${1:-up}" in
    up)
        echo "🚀 Iniciando serviços de teste no $COMPOSE_FILE..."
        $COMPOSE_CMD up -d postgres_test redis_test
        
        echo "⏳ Aguardando serviços ficarem prontos..."
        sleep 5
        
        echo "📦 Instalando dependências (localmente)..."
        cd ../backend && composer install --no-interaction 2>/dev/null || true
        
        echo "🔑 Gerando chave da aplicação..."
        php artisan key:generate --env=testing 2>/dev/null || true
        
        echo "🗄️  Executando migrations no banco de teste..."
        php artisan migrate --env=testing --force --database=pgsql || \
        php artisan migrate --env=testing --force
        
        echo "✅ Ambiente de testes pronto!"
        echo ""
        echo "Para executar testes (localmente):"
        echo "  cd backend && php artisan test"
        echo ""
        echo "Ou use:"
        echo "  ./test-setup.sh test $COMPOSE_FILE"
        ;;
    
    down)
        echo "🛑 Parando serviços de teste..."
        $COMPOSE_CMD stop postgres_test redis_test
        echo "✅ Serviços de teste parados!"
        ;;
    
    test)
        echo "🧪 Executando testes (localmente)..."
        cd ../backend && php artisan test
        ;;
    
    migrate)
        echo "🗄️  Executando migrations (localmente)..."
        cd ../backend && php artisan migrate:fresh --env=testing --force
        ;;
    
    shell)
        echo "🐚 Acessando PostgreSQL de teste..."
        $COMPOSE_CMD exec postgres_test psql -U postgres -d hubphp_test
        ;;
    
    clean)
        echo "🧹 Limpando volumes de teste..."
        $COMPOSE_CMD stop postgres_test redis_test
        docker volume rm ${COMPOSE_FILE%.yml}_postgres_test_data ${COMPOSE_FILE%.yml}_redis_test_data 2>/dev/null || true
        echo "✅ Volumes de teste removidos!"
        ;;
    
    *)
        echo "Uso: ./test-setup.sh [comando] [compose-file]"
        echo ""
        echo "Comandos disponíveis:"
        echo "  up      - Inicia serviços de teste (postgres_test, redis_test)"
        echo "  down    - Para serviços de teste"
        echo "  test    - Executa testes PHPUnit (localmente)"
        echo "  migrate - Executa migrations no banco de teste (localmente)"
        echo "  shell   - Acessa PostgreSQL de teste"
        echo "  clean   - Remove volumes de teste (limpa dados)"
        echo ""
        echo "Compose files:"
        echo "  docker-compose-dev.yml (padrão)"
        echo "  docker-compose.yml"
        exit 1
        ;;
esac

