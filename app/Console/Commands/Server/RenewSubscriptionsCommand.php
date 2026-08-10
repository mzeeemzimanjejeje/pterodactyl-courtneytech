<?php

namespace Pterodactyl\Console\Commands\Server;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Transaction;
use Pterodactyl\Services\Servers\SuspensionService;

class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'p:server:renew-subscriptions {--dry-run : Report renewals without changing balances or server state}';

    protected $description = 'Renew 30-day user servers from wallet balances and suspend unpaid servers.';

    public function __construct(private SuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        Server::query()
            ->with('user')
            ->where('subscription_exempt', false)
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<=', $now)
            ->chunkById(100, function ($servers) use ($dryRun, $now) {
                foreach ($servers as $candidate) {
                    $result = DB::transaction(function () use ($candidate, $dryRun, $now) {
                        $server = Server::query()->lockForUpdate()->find($candidate->id);
                        if (!$server || $server->subscription_exempt || !$server->subscription_expires_at || $server->subscription_expires_at->isFuture()) {
                            return ['action' => 'skipped'];
                        }

                        $user = $server->user()->lockForUpdate()->first();
                        if (!$user) {
                            return ['action' => 'skipped'];
                        }

                        if ($user->root_admin) {
                            if (!$dryRun) {
                                $server->forceFill([
                                    'subscription_exempt' => true,
                                    'subscription_expires_at' => null,
                                    'subscription_price' => null,
                                ])->save();
                            }
                            return ['action' => 'exempt', 'server' => $server];
                        }

                        $price = round((float) $server->subscription_price, 2);
                        if ($price <= 0 || (float) $user->wallet_balance < $price) {
                            return ['action' => 'suspend', 'server' => $server, 'price' => $price, 'balance' => (float) $user->wallet_balance];
                        }

                        if ($dryRun) {
                            return ['action' => 'renew', 'server' => $server, 'price' => $price];
                        }

                        $user->decrement('wallet_balance', $price);
                        Transaction::create([
                            'user_id' => $user->id,
                            'type' => 'charge',
                            'amount' => $price,
                            'status' => 'success',
                            'description' => '30-day server renewal: ' . $server->name . ' (#' . $server->id . ')',
                        ]);

                        $server->forceFill([
                            'subscription_expires_at' => $now->copy()->addDays(30),
                        ])->save();

                        return ['action' => 'renew', 'server' => $server, 'price' => $price];
                    });

                    $server = $result['server'] ?? null;
                    if (!$server) {
                        continue;
                    }

                    if ($result['action'] === 'renew') {
                        if (!$dryRun && $server->isSuspended()) {
                            try {
                                $this->suspensionService->toggle($server->fresh(), SuspensionService::ACTION_UNSUSPEND);
                            } catch (\Throwable $exception) {
                                Log::error('Unable to unsuspend renewed server.', ['server_id' => $server->id, 'exception' => $exception]);
                            }
                        }
                        $this->info(($dryRun ? 'Would renew: ' : 'Renewed: ') . $server->name);
                    } elseif ($result['action'] === 'suspend') {
                        if (!$dryRun) {
                            try {
                                $this->suspensionService->toggle($server, SuspensionService::ACTION_SUSPEND);
                            } catch (\Throwable $exception) {
                                Log::error('Unable to suspend unpaid server.', ['server_id' => $server->id, 'exception' => $exception]);
                            }
                        }
                        $this->warn(($dryRun ? 'Would suspend: ' : 'Suspended: ') . $server->name . ' because the wallet balance is insufficient.');
                    } elseif ($result['action'] === 'exempt') {
                        $this->info('Exempted admin server: ' . $server->name);
                    }
                }
            });

        return self::SUCCESS;
    }
}

