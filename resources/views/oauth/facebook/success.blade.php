<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facebook OAuth - Success</title>
    <meta http-equiv="refresh" content="0;url={{ $redirect_url }}">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #1877f2 0%, #42a5f5 100%);
            color: white;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        a {
            color: white;
            text-decoration: underline;
        }
    </style>
    <script>
        // Redirect imediato via JavaScript (fallback)
        (function() {
            var redirectUrl = '{{ $redirect_url }}';
            
            // Se for popup, usa postMessage
            if (window.opener && !window.opener.closed) {
                console.log('[FACEBOOK OAuth Callback] Enviando mensagem para opener (popup)');
                try {
                    const message = {
                        type: 'facebook_oauth_success',
                        access_token: {!! json_encode($data['access_token'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT) !!},
                        expires_in: {{ isset($data['expires_in']) ? (int)$data['expires_in'] : 0 }},
                        token_type: {!! json_encode($data['token_type'] ?? 'bearer', JSON_HEX_APOS | JSON_HEX_QUOT) !!},
                        state: {!! json_encode($data['state'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT) !!}
                    };
                    
                    console.log('[FACEBOOK OAuth Callback] Enviando:', message);
                    
                    // Envia para o opener (pode ser o parent do iframe ou a janela que abriu o popup)
                    window.opener.postMessage(message, '*');
                    
                    // Tenta também enviar para window.top (caso o opener seja um iframe)
                    if (window.top && window.top !== window.self) {
                        window.top.postMessage(message, '*');
                    }
                    
                    // Fecha popup após enviar mensagem
                    setTimeout(function() {
                        console.log('[FACEBOOK OAuth Callback] Fechando popup...');
                        window.close();
                    }, 500);
                } catch (e) {
                    console.error('[FACEBOOK OAuth Callback] Erro ao enviar postMessage:', e);
                    // Fallback para redirect normal
                    window.location.href = redirectUrl;
                }
            } else {
                console.log('[FACEBOOK OAuth Callback] Não é popup, redirecionando...');
                // Redirect normal se não for popup
                window.location.href = redirectUrl;
            }
        })();
    </script>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h1>Autorização Concluída!</h1>
        <p>Redirecionando...</p>
        <p><a href="{{ $redirect_url }}">Clique aqui se não for redirecionado automaticamente</a></p>
    </div>
</body>
</html>

