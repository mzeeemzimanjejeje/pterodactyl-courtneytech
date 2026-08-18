<?php

namespace Pterodactyl\Http\Controllers\Client;

use DB;
use Log;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\ResourcePrice;
use Pterodactyl\Models\ServerPurchasePayment;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Transaction;
use Pterodactyl\Services\Servers\ServerCreationService;

class ServerPurchasePaymentController extends Controller
{
    public function __construct(protected ServerCreationService $creationService)
    {
    }

    public function initialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_name' => 'required|string|between:1,191',
            'plan_id' => 'nullable|integer|exists:plans,id',
            'phone' => 'nullable|string|max:32',
            'nest_id' => 'nullable|integer|exists:nests,id',
            'egg_id' => 'required|integer|exists:eggs,id',
            'memory' => 'required|integer|min:0',
            'disk' => 'required|integer|min:0',
            'cpu' => 'required|integer|min:0',
            'databases' => 'required|integer|min:0',
            'backups' => 'required|integer|min:0',
            'allocations' => 'required|integer|min:1',
            'country_code' => 'nullable|string|size:2|regex:/^[A-Za-z]{2}$/',
        ]);

        $user = auth()->user();
        $countryCode = strtoupper($data['country_code'] ?? $user->country_code ?? '');
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return response()->json(['error' => 'Your account country must be selected before purchasing a server.'], 422);
        }
        if ($user->country_code && strtoupper($user->country_code) !== $countryCode) {
            return response()->json(['error' => 'The selected country does not match your account country.'], 422);
        }

        $plan = !empty($data['plan_id']) ? Plan::whereKey($data['plan_id'])->where('is_active', true)->first() : null;
        $eggQuery = Egg::whereKey($data['egg_id']);
        if (!empty($data['nest_id'])) {
            $eggQuery->where('nest_id', $data['nest_id']);
        }
        $egg = $eggQuery->first();
        if (!$egg) {
            return response()->json(['error' => 'The selected Egg does not belong to the selected Nest.'], 422);
        }

        $isUnlimited = $plan && str_contains(strtolower($plan->name), 'unlimited');
        if ($plan) {
            $amount = $isUnlimited ? ($countryCode === 'KE' ? 120.00 : 150.00) : (float) $plan->price;
        } else {
            $prices = ResourcePrice::query()->where('is_active', true)->whereNotNull('resource_key')->get()->keyBy('resource_key');
            $amount = round(
                ($data['memory'] / 1024) * (float) ($prices['ram']->price_kes ?? 0)
                + ($data['disk'] / 1024) * (float) ($prices['disk']->price_kes ?? 0)
                + ($data['cpu'] / 100) * (float) ($prices['cpu']->price_kes ?? 0)
                + $data['databases'] * (float) ($prices['database']->price_kes ?? 0)
                + $data['backups'] * (float) ($prices['backup']->price_kes ?? 0)
                + $data['allocations'] * (float) ($prices['allocation']->price_kes ?? 0),
                2
            );
            if ($amount <= 0) return response()->json(['error' => 'This custom configuration has no payable price configured.'], 422);
            $cap = (float) (Plan::where('is_active', true)->max('price') ?? 0);
            if ($cap > 0 && $amount > $cap) return response()->json(['error' => 'This configuration exceeds the maximum allowed price.'], 422);
        }
        $gateway = $countryCode === 'KE' ? 'courtneytech' : 'paystack';
        $reference = 'SP-' . strtoupper(bin2hex(random_bytes(8)));

        $payment = ServerPurchasePayment::create([
            'user_id' => $user->id,
            'plan_id' => $plan?->id,
            'reference' => $reference,
            'gateway' => $gateway,
            'amount' => $amount,
            'currency' => 'KES',
            'status' => 'pending',
            'payload' => $data + ['country_code' => $countryCode, 'phone' => $request->input('phone')],
        ]);

        try {
            if ($gateway === 'paystack') {
                return response()->json([
                    'gateway' => 'paystack',
                    'public_key' => config('services.paystack.public_key'),
                    'reference' => $reference,
                    'email' => $user->email,
                    'amount' => (int) round($amount * 100),
                ]);
            }

            $base = (string) config('services.courtneytech.base_url');
            $key = (string) config('services.courtneytech.api_key');
            $secret = (string) config('services.courtneytech.api_secret');
            $account = (int) config('services.courtneytech.account_id');
            if (!$base || !$key || !$secret || !$account) {
                throw new \RuntimeException('CourtneyTech is not configured.');
            }

            $phone = preg_replace('/\D+/', '', (string) $request->input('phone')) ?? '';
            if (str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
            if (strlen($phone) === 9) $phone = '254' . $phone;
            if (!preg_match('/^2547\d{8}$/', $phone)) {
                return response()->json(['error' => 'A valid Kenyan M-Pesa phone number is required.'], 422);
            }

            $response = (new HttpClient())->post($base . '/v2/stkpush', [
                'headers' => ['X-API-Key' => $key, 'X-API-Secret' => $secret, 'Content-Type' => 'application/json'],
                'json' => ['payment_account_id' => $account, 'phone' => $phone, 'amount' => (int) $amount, 'reference' => $reference, 'description' => 'Server creation'],
                'http_errors' => false,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?: [];
            $checkout = $body['checkout_request_id'] ?? $body['checkoutRequestId'] ?? null;
            if ($response->getStatusCode() >= 400 || ($body['success'] ?? false) !== true || !$checkout) {
                throw new \RuntimeException($body['message'] ?? 'CourtneyTech payment initialization failed.');
            }
            $payment->update(['gateway_reference' => $checkout]);

            return response()->json(['gateway' => 'courtneytech', 'reference' => $reference, 'message' => 'Approve the M-Pesa prompt on your phone.']);
        } catch (\Throwable $exception) {
            $payment->update(['status' => 'failed']);
            Log::error('Server payment initialization failed: ' . $exception->getMessage());
            return response()->json(['error' => 'Unable to initialize payment.'], 502);
        }
    }

    public function renew(Server $server): JsonResponse
    {
        $user = auth()->user();
        if ((int) $server->owner_id !== (int) $user->id) return response()->json(['error' => 'Server not found.'], 404);
        if (!$server->renewal_enabled) return response()->json(['error' => 'Renewal is disabled for this server.'], 422);
        $price = (float) ($server->renewal_price ?? $server->plan?->price ?? 0);
        if ($price <= 0) return response()->json(['error' => 'This server has no renewal price configured.'], 422);

        try {
            DB::transaction(function () use ($server, $user, $price) {
                $lockedUser = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $lockedServer = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
                if ((float) $lockedUser->wallet_balance < $price) throw new \RuntimeException('Insufficient wallet balance. Please top up first.');
                $lockedUser->decrement('wallet_balance', $price);
                $from = $lockedServer->next_renewal_at && $lockedServer->next_renewal_at->isFuture() ? $lockedServer->next_renewal_at : now();
                $lockedServer->forceFill(['next_renewal_at' => $from->copy()->addDays(30), 'renewal_enabled' => true])->save();
                Transaction::create(['user_id' => $lockedUser->id, 'type' => 'charge', 'amount' => $price, 'status' => 'success', 'description' => 'Manual 30-day renewal for server #' . $lockedServer->id]);
            });
        } catch (\Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
        return response()->json(['status' => 'success', 'message' => 'Server renewed for 30 days.']);
    }

    public function status(string $reference): JsonResponse
    {
        $payment = ServerPurchasePayment::where('reference', $reference)->where('user_id', auth()->id())->first();
        if (!$payment) return response()->json(['error' => 'Payment not found.'], 404);
        if ($payment->status === 'success') return response()->json(['status' => 'success', 'server_id' => $payment->server_id]);
        if ($payment->status === 'failed') return response()->json(['status' => 'failed']);

        $verified = $payment->gateway === 'paystack' ? $this->verifyPaystack($payment) : $this->verifyCourtney($payment);
        if ($verified) {
            try {
                $serverId = $this->provision($payment);
                $payment->update(['status' => 'success', 'server_id' => $serverId, 'confirmed_at' => now()]);
            } catch (\Throwable $exception) {
                Log::error('Confirmed server payment provisioning failed: ' . $exception->getMessage());
                return response()->json(['status' => 'confirmed_provisioning_failed'], 500);
            }
        }
        $payment->refresh();
        return response()->json(['status' => $payment->status, 'server_id' => $payment->server_id]);
    }

    protected function verifyPaystack(ServerPurchasePayment $payment): bool
    {
        $response = (new HttpClient())->get('https://api.paystack.co/transaction/verify/' . rawurlencode($payment->reference), ['headers' => ['Authorization' => 'Bearer ' . config('services.paystack.secret_key')], 'http_errors' => false]);
        $body = json_decode((string) $response->getBody(), true) ?: [];
        $amount = isset($body['data']['amount']) ? ((float) $body['data']['amount']) / 100 : null;
        if (($body['data']['status'] ?? null) === 'success' && $amount !== null && abs($amount - (float) $payment->amount) < 0.01) return true;
        if (in_array($body['data']['status'] ?? null, ['failed', 'abandoned', 'reversed'], true)) $payment->update(['status' => 'failed']);
        return false;
    }

    protected function verifyCourtney(ServerPurchasePayment $payment): bool
    {
        if (!$payment->gateway_reference) return false;
        $response = (new HttpClient())->post(config('services.courtneytech.base_url') . '/v2/status', ['headers' => ['X-API-Key' => config('services.courtneytech.api_key'), 'X-API-Secret' => config('services.courtneytech.api_secret'), 'Content-Type' => 'application/json'], 'json' => ['checkout_request_id' => $payment->gateway_reference], 'http_errors' => false]);
        $body = json_decode((string) $response->getBody(), true) ?: [];
        $amount = $body['amountKes'] ?? $body['amount_kes'] ?? $body['amount'] ?? null;
        if (($body['status'] ?? null) === 'completed' && is_numeric($amount) && abs((float) $amount - (float) $payment->amount) < 0.01) return true;
        if (in_array($body['status'] ?? null, ['cancelled', 'failed'], true)) $payment->update(['status' => 'failed']);
        return false;
    }

    protected function provision(ServerPurchasePayment $payment): int
    {
        return DB::transaction(function () use ($payment) {
            $locked = ServerPurchasePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->server_id) return (int) $locked->server_id;
            $data = $locked->payload;
            $eggQuery = Egg::whereKey($data['egg_id']);
            if (!empty($data['nest_id'])) $eggQuery->where('nest_id', $data['nest_id']);
            $egg = $eggQuery->firstOrFail();
            $dockerImage = is_array($egg->docker_images) && count($egg->docker_images) ? array_values($egg->docker_images)[0] : null;
            $environment = [];
            foreach ($egg->variables as $variable) $environment[$variable->env_variable] = $variable->default_value;
            $locations = Location::query()->pluck('id')->toArray();
            if (!$locations) throw new \RuntimeException('No server locations are configured.');
            $plan = $locked->plan;
            $server = $this->creationService->handle(['name' => trim($data['server_name']), 'description' => 'CREATED BY COURTNEY', 'owner_id' => $locked->user_id, 'memory' => $data['memory'], 'swap' => 0, 'disk' => $data['disk'], 'io' => 500, 'cpu' => $data['cpu'], 'database_limit' => $data['databases'], 'allocation_limit' => $data['allocations'], 'backup_limit' => $data['backups'], 'egg_id' => $egg->id, 'nest_id' => $egg->nest_id, 'startup' => $egg->startup, 'image' => $dockerImage, 'environment' => $environment, 'start_on_completion' => true], (new DeploymentObject())->setLocations($locations)->setDedicated(false));
            $server->forceFill(['plan_id' => $plan?->id, 'renewal_price' => $locked->amount, 'next_renewal_at' => now()->addDays(30), 'renewal_enabled' => true])->save();
            return (int) $server->id;
        });
    }
}
