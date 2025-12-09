#!/bin/bash
# Script para monitorar logs de uma requisição específica

echo "🔍 Monitorando logs em tempo real..."
echo "Pressione Ctrl+C para parar"
echo ""

docker exec hubphp_backend_dev tail -f /var/www/html/storage/logs/laravel.log | grep --line-buffered -E "\[CORS\]|\[ENSURE_ACCOUNT_ACCESS\]|\[CONVERSATIONS\]"

