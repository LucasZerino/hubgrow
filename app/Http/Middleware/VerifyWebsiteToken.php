<?php

namespace App\Http\Middleware;

use App\Models\Channel\WebWidgetChannel;
use App\Support\Current;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware VerifyWebsiteToken
 * 
 * Verifica o website_token do WebWidget e define o contexto.
 * Garante que as requisições do widget sejam autenticadas.
 * 
 * @package App\Http\Middleware
 */
class VerifyWebsiteToken
{
    /**
     * Handle an incoming request.
     * 
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $websiteToken = $request->input('website_token') 
            ?? $request->header('X-Website-Token')
            ?? $request->query('website_token');

        if (empty($websiteToken)) {
            return response()->json(['error' => 'Website token is required'], 401);
        }

        $channel = WebWidgetChannel::where('website_token', $websiteToken)->first();

        if (!$channel || !$channel->account || !$channel->account->isActive()) {
            return response()->json(['error' => 'Invalid website token'], 401);
        }

        // Define contexto para multi-tenancy
        Current::setAccount($channel->account);

        // Adiciona channel e inbox ao request para uso nos controllers
        $request->merge([
            'web_widget_channel' => $channel,
            'inbox' => $channel->inbox,
        ]);

        return $next($request);
    }
}

