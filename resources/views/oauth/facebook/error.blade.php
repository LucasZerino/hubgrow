<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facebook OAuth - Error</title>
    <meta http-equiv="refresh" content="3;url={{ $redirect_url }}">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .container {
            text-align: center;
            padding: 2rem;
            max-width: 500px;
        }
        .error-icon {
            font-size: 48px;
            margin-bottom: 1rem;
        }
        a {
            color: white;
            text-decoration: underline;
        }
    </style>
    <script>
        // Se for popup, usa postMessage para comunicar erro
        (function() {
            var redirectUrl = '{{ $redirect_url }}';
            
            if (window.opener && !window.opener.closed) {
                try {
                    window.opener.postMessage({
                        type: 'facebook_oauth_error',
                        error: 'oauth_failed',
                        error_description: '{{ $message }}',
                    }, '{{ $frontend_url }}');
                    
                    // Fecha popup após enviar mensagem
                    setTimeout(function() {
                        window.close();
                    }, 1000);
                } catch (e) {
                    console.error('Erro ao enviar postMessage:', e);
                    // Fallback para redirect normal
                    setTimeout(function() {
                        window.location.href = redirectUrl;
                    }, 3000);
                }
            } else {
                // Redirect normal se não for popup
                setTimeout(function() {
                    window.location.href = redirectUrl;
                }, 3000);
            }
        })();
    </script>
</head>
<body>
    <div class="container">
        <div class="error-icon">⚠️</div>
        <h1>Erro na Autorização</h1>
        <p>{{ $message }}</p>
        <p>Redirecionando em 3 segundos...</p>
        <p><a href="{{ $redirect_url }}">Clique aqui para continuar</a></p>
    </div>
</body>
</html>

