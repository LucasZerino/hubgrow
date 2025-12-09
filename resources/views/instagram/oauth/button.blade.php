<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorizar Instagram</title>
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
            background: linear-gradient(135deg, #833AB4 0%, #FD1D1D 50%, #FCAF45 100%);
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
        
        console.log('[INSTAGRAM OAuth Button] Página carregada', {
            isInIframe,
            authUrl: authUrl.substring(0, 100) + '...',
            frontendUrl,
        });
        
        // Função para iniciar OAuth
        function startOAuth(event) {
            if (event) {
                event.preventDefault();
            }
            
            console.log('[INSTAGRAM OAuth Button] Iniciando OAuth', {
                isInIframe,
                authUrl: authUrl.substring(0, 100) + '...',
            });
            
            // Se estiver em iframe, comunica com o parent
            if (isInIframe) {
                // Envia mensagem para o parent abrir popup
                const message = {
                    type: 'instagram_oauth_start',
                    auth_url: authUrl,
                };
                
                console.log('[INSTAGRAM OAuth Button] Enviando mensagem para parent', message);
                window.parent.postMessage(message, '*');
                
                // Tenta abrir popup também do iframe (pode não funcionar)
                // O parent deve abrir o popup após receber a mensagem
            } else {
                // Se não estiver em iframe, redireciona diretamente
                console.log('[INSTAGRAM OAuth Button] Redirecionando diretamente');
                window.location.href = authUrl;
            }
        }
        
        // Auto-click após 500ms se estiver em iframe (para testar)
        // Remover em produção se não quiser auto-click
        // if (isInIframe) {
        //     setTimeout(() => {
        //         console.log('[INSTAGRAM OAuth Button] Auto-click após 500ms');
        //     }, 500);
        // }
        
        // Listener para mensagens do parent (se estiver em iframe)
        // Ignora mensagens que não são relacionadas ao nosso OAuth
        window.addEventListener('message', function(event) {
            // Filtra apenas mensagens relevantes para evitar spam de logs
            if (event.data.type === 'instagram_oauth_start' || event.data.type === 'click') {
                console.log('[INSTAGRAM OAuth Button] Mensagem recebida do parent', event.data);
                // Parent quer iniciar OAuth
                startOAuth(null);
            }
        });
        
        // Expor função globalmente para debug
        window.startInstagramOAuth = startOAuth;
    </script>
</head>
<body>
    <div class="container">
        <button onclick="startOAuth(event);" class="oauth-button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
            </svg>
            Conectar Instagram
        </button>
    </div>

