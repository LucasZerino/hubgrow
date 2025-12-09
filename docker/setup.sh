#!/bin/bash

# Script de setup inicial para desenvolvimento
# Executa migrações e seeders no backend

echo "🔧 Configurando ambiente de desenvolvimento..."

# Verificar se os containers estão rodando
if ! docker-compose -f docker-compose-dev.yml ps | grep -q "hubphp_backend_dev.*Up"; then
    echo "❌ Backend container não está rodando. Inicie com: docker-compose -f docker-compose-dev.yml up -d"
    exit 1
fi

echo "📦 Instalando dependências do backend..."
docker-compose -f docker-compose-dev.yml exec -T backend composer install --no-interaction

echo "🔑 Gerando chave da aplicação..."
docker-compose -f docker-compose-dev.yml exec -T backend php artisan key:generate --force

echo "🗄️  Rodando migrações..."
docker-compose -f docker-compose-dev.yml exec -T backend php artisan migrate --force

echo "🌱 Rodando seeders..."
docker-compose -f docker-compose-dev.yml exec -T backend php artisan db:seed --class=SuperAdminSeeder

echo "✅ Setup completo!"
echo ""
echo "🌐 Acesse:"
echo "   - Frontend: http://localhost:5173"
echo "   - Backend:  http://localhost:8000"
echo "   - MinIO:    http://localhost:9001"
echo "   - MailHog:  http://localhost:8025"

