<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\PaymentService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private readonly PaymentService $service) {}

    /**
     * POST /webhooks/psp/{channel}
     *
     * En production : vérifier la signature HMAC du PSP avant tout traitement.
     * En dev : endpoint ouvert, payload simulé.
     */
    public function handle(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string'],
            'psp_reference'   => ['required', 'string'],
            'status'          => ['required', 'string', 'in:confirmed,failed'],
        ]);

        Log::info("Webhook PSP [{$channel}]", $data);

        if ($data['status'] !== 'confirmed') {
            // Paiement échoué côté PSP — pas d'écriture ledger
            return ApiResponse::success(['acknowledged' => true]);
        }

        $payment = $this->service->confirmFromWebhook(
            $data['idempotency_key'],
            $data['psp_reference'],
            $channel,
        );

        return ApiResponse::success(['reference' => $payment->reference, 'status' => 'confirmed']);
    }
}
