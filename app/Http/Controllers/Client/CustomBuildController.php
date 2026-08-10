<?php
namespace Pterodactyl\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Transaction;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Models\Objects\DeploymentObject;

class CustomBuildController extends Controller
{
    public function __construct(private ServerCreationService $creationService)
    {
    }

    private function ramPrice(int $ramGb): float
    {
        if ($ramGb <= 4) return 70.0;
        if ($ramGb <= 8) return 90.0;
        return 120.0;
    }

    public function options(): JsonResponse
    {
        $eggs = Egg::query()
            ->whereHas('nest')
            ->with('nest:id,name')
            ->orderBy('nest_id')
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($egg) => $egg->nest->name)
            ->map(fn ($group) => $group->map(fn ($egg) => ['id' => $egg->id, 'name' => $egg->name]));

        $maxMemory = (int) (Node::query()->where('memory', '>', 0)->max('memory') ?? 0);
        $maxRamGb = max(1, (int) floor($maxMemory / 1024));

        return response()->json([
            'eggs' => $eggs,
            'ram_options' => range(1, $maxRamGb),
            'ram_prices' => [
                ['min' => 1, 'max' => 4, 'price_kes' => 70],
                ['min' => 5, 'max' => 8, 'price_kes' => 90],
                ['min' => 9, 'max' => null, 'price_kes' => 120],
            ],
            'defaults' => [
                'disk_multiplier' => 2,
                'cpu_percent' => 100,
                'databases' => 0,
                'backups' => 0,
                'allocations' => 1,
            ],
        ]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $input = $request->validate([
            'server_name' => 'required|string|between:1,191',
            'ram_gb' => 'required|integer|min:1',
            'egg_id' => 'required|integer|exists:eggs,id',
        ]);

        $ramGb = (int) $input['ram_gb'];
        $maxMemory = (int) (Node::query()->where('memory', '>', 0)->max('memory') ?? 0);
        $maxRamGb = max(1, (int) floor($maxMemory / 1024));
        if ($ramGb > $maxRamGb) {
            return response()->json(['error' => 'That RAM size is not available on this node. Please choose a smaller size.'], 422);
        }

        $egg = Egg::query()->with('nest')->findOrFail($input['egg_id']);
        $memory = $ramGb * 1024;
        $disk = $memory * 2;
        $price = $ramGb <= 4 ? 70.0 : ($ramGb <= 8 ? 90.0 : 120.0);
        $user = auth()->user();

        if ((float) $user->wallet_balance < $price) {
            return response()->json(['error' => 'Insufficient wallet balance. You need KSh ' . number_format($price, 2) . ' for this server.'], 422);
        }

        $dockerImage = is_array($egg->docker_images) && count($egg->docker_images) > 0
            ? array_values($egg->docker_images)[0]
            : null;
        $environment = [];
        foreach ($egg->variables as $variable) {
            $default = $variable->default_value;
            if (($default === null || $default === '') && str_contains((string) $variable->rules, 'required')) {
                $default = str()->random(32);
            }
            $environment[$variable->env_variable] = $default;
        }

        $locationIds = Location::query()->pluck('id')->toArray();
        if (empty($locationIds)) {
            return response()->json(['error' => 'No server locations are configured yet.'], 422);
        }
        $deployment = (new DeploymentObject())->setLocations($locationIds)->setDedicated(false);

        $displayName = strtoupper(trim($input['server_name']));
        $displayName = preg_replace("/\\s*'S SERVER$/i", '', $displayName) . "'S SERVER";

        try {
            $server = $this->creationService->handle([
                'name' => $displayName,
                'description' => 'CREATED BY COURTNEY',
                'owner_id' => $user->id,
                'memory' => $memory,
                'swap' => 0,
                'disk' => $disk,
                'io' => 500,
                'cpu' => 100,
                'database_limit' => 0,
                'allocation_limit' => 1,
                'backup_limit' => 0,
                'egg_id' => $egg->id,
                'nest_id' => $egg->nest_id,
                'startup' => $egg->startup,
                'image' => $dockerImage,
                'environment' => $environment,
                'start_on_completion' => true,
            ], $deployment);
        } catch (\Throwable $exception) {
            Log::error('Custom build server creation failed: ' . $exception->getMessage());
            return response()->json(['error' => 'Unable to provision a server right now. No charge was made — please try again shortly or contact support.'], 500);
        }

        \DB::transaction(function () use ($user, $price, $server, $ramGb) {
            $user->decrement('wallet_balance', $price);
            Transaction::create([
                'user_id' => $user->id,
                'type' => 'charge',
                'amount' => $price,
                'status' => 'success',
                'description' => 'Custom build ' . $ramGb . ' GB (server #' . $server->id . ')',
            ]);
        });

        return response()->json(['server_id' => $server->id, 'message' => 'Server created successfully.']);
    }
}
