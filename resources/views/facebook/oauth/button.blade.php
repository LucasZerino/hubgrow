<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorizar Facebook</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: transparent;
        }
        
        .container {
            text-align: center;
            width: 100%;
            padding: 8px;
        }
        
        .oauth-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #1877F2;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }
        
        .oauth-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            background: #166FE5;
        }
        
        .oauth-button:active {
            transform: translateY(0);
        }
    </style>
    <script>
        // Detecta se está em iframe
        const isInIframe = window.self !== window.top;
        // IMPORTANTE: Usa json_encode para não escapar HTML entities (& se torna &amp;)
        const authUrl = @json($auth_url);
        const frontendUrl = @json($frontend_url ?? '');
        
        console.log('[FACEBOOK OAuth Button] Página carregada', {
            isInIframe,
            authUrl: authUrl.substring(0, 100) + '...',
            frontendUrl,
        });
        
        // Função para iniciar OAuth
        function startOAuth(event) {
            if (event) {
                event.preventDefault();
            }
            
            console.log('[FACEBOOK OAuth Button] Iniciando OAuth', {
                isInIframe,
                authUrl: authUrl.substring(0, 100) + '...',
            });
            
            // Se estiver em iframe, comunica com o parent
            if (isInIframe) {
                // Envia mensagem para o parent abrir popup
                const message = {
                    type: 'facebook_oauth_start',
                    auth_url: authUrl,
                };
                
                console.log('[FACEBOOK OAuth Button] Enviando mensagem para parent', message);
                window.parent.postMessage(message, '*');
                
                // Tenta abrir popup também do iframe (pode não funcionar)
                // O parent deve abrir o popup após receber a mensagem
            } else {
                // Se não estiver em iframe, redireciona diretamente
                console.log('[FACEBOOK OAuth Button] Redirecionando diretamente');
                window.location.href = authUrl;
            }
        }
        
        // Listener para mensagens do parent (se estiver em iframe)
        // Ignora mensagens que não são relacionadas ao nosso OAuth
        window.addEventListener('message', function(event) {
            // Filtra apenas mensagens relevantes para evitar spam de logs
            if (event.data.type === 'facebook_oauth_start' || event.data.type === 'click') {
                console.log('[FACEBOOK OAuth Button] Mensagem recebida do parent', event.data);
                // Parent quer iniciar OAuth
                startOAuth(null);
            }
        });
        
        // Expor função globalmente para debug
        window.startFacebookOAuth = startOAuth;
    </script>
</head>
<body>
    <div class="container">
        <button onclick="startOAuth(event);" class="oauth-button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
            Conectar Facebook
        </button>
    </div>
</body>
</html>

