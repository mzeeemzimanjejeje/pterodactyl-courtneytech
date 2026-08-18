<?php

namespace Pterodactyl\Http\Controllers\Client;

use Log;
use Illuminate\Http\Request;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Transaction;
use Pterodactyl\Models\ResourcePrice;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Services\Servers\ServerCreationService;

class CustomBuildController extends Controller
{
    public function __construct(protected ServerCreationService $creationService)
    {
    }

    public function options(): JsonResponse
    {
        $nests = Nest::query()
            ->whereHas('eggs')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->values()
            ->map(fn (Nest $nest) => ['id' => $nest->id, 'name' => $nest->name]);

        $eggs = Egg::query()
            ->whereIn('nest_id', $nests->pluck('id'))
            ->orderBy('nest_id')
            ->orderBy('name')
            ->get(['id', 'nest_id', 'name'])
            ->values()
            ->map(fn (Egg $egg) => [
                'id' => $egg->id,
                'nest_id' => $egg->nest_id,
                'name' => $egg->name,
            ]);

        $prices = ResourcePrice::where('is_active', true)
            ->whereNotNull('resource_key')
            ->get()
            ->keyBy('resource_key')
            ->map(fn ($item) => [
                'price_kes' => (float) $item->price_kes,
                'unit_label' => $item->unit_label,
            ]);

        // Keep the builder usable on installations where the optional pricing rows
        // have not yet been created; administrators can override these values later.
        if ($prices->isEmpty()) {
            $prices = collect([
                'ram' => ['price_kes' => 100.0, 'unit_label' => 'per GB'],
                'disk' => ['price_kes' => 0.0, 'unit_label' => 'per GB'],
                'cpu' => ['price_kes' => 0.0, 'unit_label' => 'per 100%'],
                'database' => ['price_kes' => 0.0, 'unit_label' => 'each'],
                'backup' => ['price_kes' => 0.0, 'unit_label' => 'each'],
                'allocation' => ['price_kes' => 0.0, 'unit_label' => 'per port'],
            ]);
        }

        $cap = (float) (Plan::where('is_active', true)->max('price') ?? 0);

        return response()->json([
            'nests' => $nests,
            'eggs' => $eggs,
            'prices' => $prices,
            'price_cap' => $cap,
        ]);
    }

    protected function calculatePrice(array $data, $prices): float
    {
        $total = 0;
        $total += ($data['memory'] / 1024) * ($prices['ram']['price_kes'] ?? 0);
        $total += ($data['disk'] / 1024) * ($prices['disk']['price_kes'] ?? 0);
        $total += ($data['cpu'] / 100) * ($prices['cpu']['price_kes'] ?? 0);
        $total += $data['databases'] * ($prices['database']['price_kes'] ?? 0);
        $total += $data['backups'] * ($prices['backup']['price_kes'] ?? 0);
        $total += $data['allocations'] * ($prices['allocation']['price_kes'] ?? 0);

        return round($total, 2);
    }

    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nest_id' => 'required|integer|exists:nests,id',
            'egg_id' => 'required|integer|exists:eggs,id',
            'memory' => 'required|integer|min:0',
            'disk' => 'required|integer|min:0',
            'cpu' => 'required|integer|min:0',
            'databases' => 'required|integer|min:0',
            'backups' => 'required|integer|min:0',
            'allocations' => 'required|integer|min:1',
        ]);

        $user = auth()->user();

        $prices = ResourcePrice::where('is_active', true)
            ->whereNotNull('resource_key')
            ->get()
            ->keyBy('resource_key');

        $price = $this->calculatePrice($data, $prices);
        $cap = (float) (Plan::where('is_active', true)->max('price') ?? 0);

        if ($cap > 0 && $price > $cap) {
            return response()->json([
                'error' => 'This configuration exceeds the maximum allowed price. Please reduce your selection.',
            ], 422);
        }

        if ((float) $user->wallet_balance < $price) {
            return response()->json(['error' => 'Insufficient wallet balance. Please top up first.'], 422);
        }

        $egg = Egg::query()
            ->where('id', $data['egg_id'])
            ->where('nest_id', $data['nest_id'])
            ->first();

        if (!$egg) {
            return response()->json(['error' => 'The selected Egg does not belong to the selected Nest.'], 422);
        }
        $dockerImage = is_array($egg->docker_images) && count($egg->docker_images) > 0
            ? array_values($egg->docker_images)[0]
            : null;

        $environment = [];
        foreach ($egg->variables as $variable) {
            $environment[$variable->env_variable] = $variable->default_value;
        }

        $locationIds = Location::query()->pluck('id')->toArray();

        if (empty($locationIds)) {
            return response()->json(['error' => 'No server locations are configured yet.'], 422);
        }

        $deployment = (new DeploymentObject())->setLocations($locationIds)->setDedicated(false);

        try {
            $server = $this->creationService->handle([
                'name' => $user->username . "'s Custom Server",
                'description' => 'Custom-built server (' . $data['memory'] . 'MB / ' . $data['disk'] . 'MB / ' . $data['cpu'] . '%).',
                'owner_id' => $user->id,
                'memory' => $data['memory'],
                'swap' => 0,
                'disk' => $data['disk'],
                'io' => 500,
                'cpu' => $data['cpu'],
                'database_limit' => $data['databases'],
                'allocation_limit' => $data['allocations'],
                'backup_limit' => $data['backups'],
                'egg_id' => $egg->id,
                'nest_id' => $egg->nest_id,
                'startup' => $egg->startup,
                'image' => $dockerImage,
                'environment' => $environment,
                'start_on_completion' => true,
            ], $deployment);
        } catch (\Throwable $exception) {
            Log::error('Custom build server creation failed: ' . $exception->getMessage());

            return response()->json([
                'error' => 'Unable to provision a server right now. No charge was made — please try again shortly or contact support.',
            ], 500);
        }

        $server->forceFill([
            'renewal_price' => $price,
            'next_renewal_at' => now()->addDays(30),
            'renewal_enabled' => true,
        ])->save();

        \DB::transaction(function () use ($user, $price, $server) {
            $user->decrement('wallet_balance', $price);

            Transaction::create([
                'user_id' => $user->id,
                'type' => 'charge',
                'amount' => $price,
                'status' => 'success',
                'description' => 'Custom build (server #' . $server->id . ')',
            ]);
        });

        return response()->json([
            'server_id' => $server->id,
            'message' => 'Server created successfully.',
        ]);
    }
}
