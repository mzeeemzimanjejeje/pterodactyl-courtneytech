<?php

namespace Pterodactyl\Http\Controllers\Client;

use Log;
use Illuminate\View\View;
use Illuminate\Http\Request;
use GuzzleHttp\Client as HttpClient;
use Pterodactyl\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Pterodactyl\Http\Controllers\Controller;

class WalletController extends Controller
{
    protected string $secretKey;
    protected string $publicKey;
    protected string $courtneyBaseUrl;
    protected string $courtneyApiKey;
    protected string $courtneyApiSecret;
    protected string $courtneyAccountId;

    public function __construct()
    {
        $this->secretKey = (string) config('services.paystack.secret_key');
        $this->publicKey = (string) config('services.paystack.public_key');
        $this->courtneyBaseUrl = (string) config('services.courtneytech.base_url');
        $this->courtneyApiKey = (string) config('services.courtneytech.api_key');
        $this->courtneyApiSecret = (string) config('services.courtneytech.api_secret');
        $this->courtneyAccountId = (string) config('services.courtneytech.account_id');
    }

    public function index(): View
    {
        return view('templates/base.core');
    }

    public function data(): JsonResponse
    {
        $user = auth()->user();

        $transactions = Transaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'balance' => (float) $user->wallet_balance,
            'transactions' => $transactions,
        ]);
    }

    public function initializeCard(Request $request): JsonResponse
    {
        $request->merge(['amount' => is_numeric($request->input('amount')) ? (float) $request->input('amount') : $request->input('amount')]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', Rule::in([150])],
        ]);
        $amount = (float) $data['amount'];

        $user = auth()->user();
        $reference = 'WT-' . strtoupper(uniqid()) . '-' . $user->id;

        Transaction::create([
            'user_id' => $user->id,
            'type' => 'deposit',
            'amount' => $amount,
            'status' => 'pending',
            'gateway' => 'paystack',
            'reference' => $reference,
            'gateway_reference' => $reference,
            'description' => 'Wallet top-up via card',
        ]);

        return response()->json([
            'public_key' => $this->publicKey,
            'reference' => $reference,
            'email' => $user->email,
            'amount' => (int) round($amount * 100),
        ]);
    }

    public function initializeMobileMoney(Request $request): JsonResponse
    {
        $request->merge(['amount' => is_numeric($request->input('amount')) ? (float) $request->input('amount') : $request->input('amount')]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', Rule::in([70, 100, 120])],
            'phone' => 'required|string',
        ]);
        $amount = (float) $data['amount'];

        if (!$this->courtneyApiKey || !$this->courtneyApiSecret || !$this->courtneyAccountId) {
            Log::error('CourtneyTech is not configured: COURTNEY_API_KEY, COURTNEY_API_SECRET, or COURTNEY_ACCOUNT_ID is missing.');

            return response()->json([
                'error' => 'The Kenya M-Pesa gateway is not configured yet. Please contact support.',
            ], 503);
        }

        $user = auth()->user();
        $reference = substr('WT' . strtoupper(bin2hex(random_bytes(5))), 0, 12);
        $phone = $this->normalizePhone($data['phone']);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'type' => 'deposit',
            'amount' => $amount,
            'status' => 'pending',
            'gateway' => 'courtneytech',
            'reference' => $reference,
            'description' => 'Courtney M-Pesa top-up',
        ]);

        try {
            $response = (new HttpClient())->post($this->courtneyBaseUrl . '/v2/stkpush', [
                'headers' => [
                    'X-API-Key' => $this->courtneyApiKey,
                    'X-API-Secret' => $this->courtneyApiSecret,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'payment_account_id' => (int) $this->courtneyAccountId,
                    'phone' => $phone,
                    'amount' => (int) round($amount),
                    'reference' => $reference,
                    'description' => 'Wallet topup',
                ],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true) ?: [];
            $checkoutReference = $body['checkout_request_id'] ?? $body['checkoutRequestId'] ?? null;

            if ($statusCode >= 400 || ($body['success'] ?? false) !== true || !$checkoutReference) {
                Log::error('CourtneyTech STK push failed', [
                    'status_code' => $statusCode,
                    'message' => $body['message'] ?? null,
                ]);

                $transaction->update(['status' => 'failed']);

                return response()->json([
                    'error' => $body['message'] ?? 'Unable to start the Kenya M-Pesa payment. Please try again.',
                ], 500);
            }

            $transaction->update(['gateway_reference' => $checkoutReference]);

            return response()->json([
                'reference' => $reference,
                'message' => 'Enter your M-Pesa PIN on your phone to complete this payment.',
            ]);
        } catch (\Throwable $exception) {
            Log::error('CourtneyTech STK push failed: ' . $exception->getMessage());
            $transaction->update(['status' => 'failed']);

            return response()->json([
                'error' => 'Unable to start the Kenya M-Pesa payment. Please try again.',
            ], 500);
        }
    }

    public function status(string $reference): JsonResponse
    {
        $transaction = Transaction::where('reference', $reference)
            ->where('user_id', auth()->id())
            ->first();

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found.'], 404);
        }

        if ($transaction->status === 'pending') {
            if ($transaction->gateway === 'courtneytech') {
                $this->verifyCourtneyAndCredit($transaction);
            } else {
                $this->verifyAndCredit($reference);
            }
            $transaction->refresh();
        }

        return response()->json([
            'status' => $transaction->status,
            'balance' => (float) auth()->user()->wallet_balance,
        ]);
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '254')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '254' . $digits;
        }

        return $digits;
    }

    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->query('reference');

        if ($reference) {
            $transaction = Transaction::where('reference', $reference)->first();
            if ($transaction && $transaction->gateway === 'courtneytech') {
                $this->verifyCourtneyAndCredit($transaction);
            } else {
                $this->verifyAndCredit($reference);
            }
        }

        return redirect('/account/wallet');
    }

    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Paystack-Signature');
        $payload = $request->getContent();
        $expected = hash_hmac('sha512', $payload, $this->secretKey);

        if (!$signature || !hash_equals($expected, $signature)) {
            Log::warning('Paystack webhook signature mismatch.');

            return response()->json(['status' => 'invalid signature'], 401);
        }

        $event = json_decode($payload, true);

        if (($event['event'] ?? null) === 'charge.success') {
            $reference = $event['data']['reference'] ?? null;

            if ($reference) {
                $this->verifyAndCredit($reference);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    protected function verifyAndCredit(string $reference): void
    {
        $transaction = Transaction::where('reference', $reference)->first();

        if (!$transaction || $transaction->status === 'success') {
            return;
        }

        try {
            $response = (new HttpClient())->get('https://api.paystack.co/transaction/verify/' . rawurlencode($reference), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secretKey,
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $status = $body['data']['status'] ?? null;
            $paidAmount = isset($body['data']['amount']) ? ((float) $body['data']['amount']) / 100 : null;

            if ($status === 'success' && $paidAmount !== null && abs($paidAmount - (float) $transaction->amount) < 0.01) {
                $this->creditSuccessfulTransaction($transaction);
            } elseif (in_array($status, ['failed', 'abandoned', 'reversed'], true)) {
                $transaction->update(['status' => 'failed']);
            }
        } catch (\Throwable $exception) {
            Log::error('Paystack verify failed: ' . $exception->getMessage());
        }
    }

    protected function verifyCourtneyAndCredit(Transaction $transaction): void
    {
        if (!$transaction->gateway_reference || $transaction->status === 'success') {
            return;
        }

        try {
            $response = (new HttpClient())->post($this->courtneyBaseUrl . '/v2/status', [
                'headers' => [
                    'X-API-Key' => $this->courtneyApiKey,
                    'X-API-Secret' => $this->courtneyApiSecret,
                    'Content-Type' => 'application/json',
                ],
                'json' => ['checkout_request_id' => $transaction->gateway_reference],
                'http_errors' => false,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?: [];
            $status = $body['status'] ?? null;
            $paidAmount = null;
            foreach (['amountKes', 'amount_kes', 'amount'] as $amountKey) {
                if (isset($body[$amountKey]) && is_numeric($body[$amountKey])) {
                    $paidAmount = (float) $body[$amountKey];
                    break;
                }
            }

            if ($status === 'completed' && $paidAmount !== null && abs($paidAmount - (float) $transaction->amount) < 0.01) {
                $this->creditSuccessfulTransaction($transaction);
            } elseif (in_array($status, ['cancelled', 'failed'], true)) {
                $transaction->update(['status' => 'failed']);
            }
        } catch (\Throwable $exception) {
            Log::error('CourtneyTech status verification failed: ' . $exception->getMessage());
        }
    }

    protected function creditSuccessfulTransaction(Transaction $transaction): void
    {
        \DB::transaction(function () use ($transaction) {
            $fresh = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

            if (!$fresh || $fresh->status === 'success') {
                return;
            }

            $fresh->update(['status' => 'success']);
            $fresh->user()->increment('wallet_balance', $fresh->amount);
        });
    }
}
