<?php

namespace App\Http\Controllers\Api\V1\Accounts\WhatsApp;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Models\Inbox;
use App\Services\WhatsApp\EmbeddedSignupService;
use App\Support\Current;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Controller AuthorizationsController
 * 
 * Gerencia autorização e reautorização de canais WhatsApp via embedded signup.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\WhatsApp
 */
class AuthorizationsController extends BaseController
{
    /**
     * Cria ou reautoriza canal WhatsApp
     * 
     * POST /api/v1/accounts/{account_id}/whatsapp/authorization
     * 
     * Se inbox_id estiver presente, realiza reautorização.
     * Caso contrário, cria novo canal.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        // Verifica limite de canais WhatsApp (apenas para novos canais)
        if (!$request->has('inbox_id')) {
            $account = Current::account();
            if (!$account->canCreateResource('whatsapp_channels')) {
                $usage = $account->getResourceUsage('whatsapp_channels');
                return response()->json([
                    'error' => 'Limite de canais WhatsApp excedido',
                    'message' => 'Você atingiu o limite de canais WhatsApp para esta conta.',
                    'usage' => $usage,
                ], 402);
            }
        }

        try {
            $this->validateEmbeddedSignupParams($request);

            $inbox = null;
            if ($request->has('inbox_id')) {
                $inbox = $this->fetchAndValidateInbox($request);
            }

            $channel = $this->processEmbeddedSignup($request, $inbox?->id);

            return $this->renderSuccessResponse($channel->inbox, $request->has('inbox_id'));
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("[WHATSAPP AUTHORIZATION] Embedded signup error: {$e->getMessage()}");
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Valida parâmetros do embedded signup
     * 
     * @param Request $request
     * @return void
     * @throws ValidationException
     */
    protected function validateEmbeddedSignupParams(Request $request): void
    {
        $request->validate([
            'code' => 'required|string',
            'business_id' => 'required|string',
            'waba_id' => 'required|string',
            'phone_number_id' => 'nullable|string',
            'inbox_id' => 'nullable|integer|exists:inboxes,id',
        ]);
    }

    /**
     * Busca e valida inbox para reautorização
     * 
     * @param Request $request
     * @return Inbox
     * @throws \Exception
     */
    protected function fetchAndValidateInbox(Request $request): Inbox
    {
        $inbox = Current::account()->inboxes()->findOrFail($request->input('inbox_id'));
        
        $this->validateReauthorizationRequired($inbox);

        return $inbox;
    }

    /**
     * Valida se reautorização é necessária
     * 
     * @param Inbox $inbox
     * @return void
     * @throws \Exception
     */
    protected function validateReauthorizationRequired(Inbox $inbox): void
    {
        $channel = $inbox->channel;

        if (!($channel instanceof \App\Models\Channel\WhatsAppChannel)) {
            throw new \Exception('Channel is not a WhatsApp channel');
        }

        // TODO: Implementar verificação de reautorização necessária
        // Por enquanto, permite sempre se for WhatsApp Cloud
        if ($channel->provider !== \App\Models\Channel\WhatsAppChannel::PROVIDER_WHATSAPP_CLOUD) {
            throw new \Exception('Reauthorization not required or not supported');
        }
    }

    /**
     * Processa embedded signup
     * 
     * @param Request $request
     * @param int|null $inboxId
     * @return \App\Models\Channel\WhatsAppChannel
     */
    protected function processEmbeddedSignup(Request $request, ?int $inboxId): \App\Models\Channel\WhatsAppChannel
    {
        $service = new EmbeddedSignupService(
            account: Current::account(),
            params: $request->only(['code', 'business_id', 'waba_id', 'phone_number_id']),
            inboxId: $inboxId
        );

        return $service->perform();
    }

    /**
     * Renderiza resposta de sucesso
     * 
     * @param Inbox $inbox
     * @param bool $isReauthorization
     * @return JsonResponse
     */
    protected function renderSuccessResponse(Inbox $inbox, bool $isReauthorization): JsonResponse
    {
        $response = [
            'success' => true,
            'id' => $inbox->id,
            'name' => $inbox->name,
            'channel_type' => 'whatsapp',
        ];

        if ($isReauthorization) {
            $response['message'] = 'Reauthorization successful';
        }

        return response()->json($response);
    }
}
