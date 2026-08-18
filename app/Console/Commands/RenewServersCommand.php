<?php

namespace Pterodactyl\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Transaction;

class RenewServersCommand extends Command
{
    protected $signature = 'servers:renew {--dry-run : Report due renewals without charging wallets}';

    protected $description = 'Charge due server renewals from wallet balances every 30 days';

    public function handle(): int
    {
        $now = Carbon::now();
        $due = Server::query()
            ->where('renewal_enabled', true)
            ->whereNotNull('next_renewal_at')
            ->where('next_renewal_at', '<=', $now)
            ->with('plan')
            ->get();

        foreach ($due as $server) {
            $price = $server->renewal_price ?? $server->plan?->price;
            if ($price === null || (float) $price <= 0) {
                $this->warn("Skipping server {$server->id}: plan price is not configured.");
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would renew server {$server->id} for KSh {$price}.");
                continue;
            }

            DB::transaction(function () use ($server, $price, $now): void {
                $locked = Server::query()->lockForUpdate()->with(['plan', 'user'])->find($server->id);
                if (!$locked || !$locked->renewal_enabled || !$locked->next_renewal_at || $locked->next_renewal_at->isFuture()) {
                    return;
                }

                $owner = $locked->user()->lockForUpdate()->first();
                $lockedPrice = (float) ($locked->renewal_price ?? $locked->plan?->price ?? 0);
                if (!$owner || $lockedPrice <= 0 || (float) $owner->wallet_balance < $lockedPrice) {
                    $this->warn("Insufficient wallet balance for server {$locked->id}; renewal remains due.");
                    return;
                }

                $owner->decrement('wallet_balance', $lockedPrice);
                $locked->forceFill([
                    'next_renewal_at' => $locked->next_renewal_at->copy()->addDays(30),
                ])->save();

                Transaction::create([
                    'user_id' => $owner->id,
                    'type' => 'charge',
                    'amount' => $lockedPrice,
                    'status' => 'success',
                    'description' => "30-day renewal for server #{$locked->id}",
                ]);
            });
        }

        return self::SUCCESS;
    }
}
