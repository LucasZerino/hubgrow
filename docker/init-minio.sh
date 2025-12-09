#!/bin/sh
# Script de inicialização do MinIO
# Cria o bucket e configura política pública automaticamente

set -e

echo "Aguardando MinIO estar pronto..."
until mc alias set local http://localhost:9000 ${MINIO_ROOT_USER} ${MINIO_ROOT_PASSWORD} 2>/dev/null; do
  echo "Aguardando MinIO..."
  sleep 2
done

echo "MinIO está pronto!"

# Cria bucket se não existir
BUCKET_NAME=${MINIO_BUCKET:-hubphp}
echo "Verificando bucket: ${BUCKET_NAME}"
if mc ls local/${BUCKET_NAME} >/dev/null 2>&1; then
  echo "Bucket ${BUCKET_NAME} já existe"
else
  echo "Criando bucket: ${BUCKET_NAME}"
  mc mb local/${BUCKET_NAME}
  echo "Bucket ${BUCKET_NAME} criado com sucesso!"
fi

# Configura política pública para download (read-only)
echo "Configurando política pública para bucket: ${BUCKET_NAME}"
mc anonymous set download local/${BUCKET_NAME}
echo "Política pública configurada!"

echo "MinIO inicializado com sucesso!"
echo "Bucket: ${BUCKET_NAME}"
echo "Endpoint: http://localhost:9000"
echo "Console: http://localhost:9001"

