<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('plans') || !Schema::hasColumn('plans', 'egg_id')) {
            return;
        }

        $egg = DB::table('eggs')->orderBy('id')->first(['id', 'nest_id']);
        if (!$egg) {
            return;
        }

        $now = now();
        $catalog = [];
        for ($gb = 1; $gb <= 10; $gb++) {
            $catalog[] = [
                'name' => $gb . ' GB',
                'description' => $gb . ' GB RAM server plan.',
                'price' => $gb <= 4 ? 100.00 : 130.00,
                'memory' => $gb * 1024,
                'sort_order' => $gb,
            ];
        }
        $catalog[] = [
            'name' => 'Unlimited',
            'description' => 'Unlimited panel plan with 30-day billing.',
            'price' => 150.00,
            'memory' => 0,
            'sort_order' => 11,
        ];

        foreach ($catalog as $item) {
            $existing = DB::table('plans')->whereRaw('LOWER(name) = ?', [strtolower($item['name'])])->first();
            $values = [
                'egg_id' => $existing?->egg_id ?: $egg->id,
                'nest_id' => $existing?->nest_id ?: $egg->nest_id,
                'description' => $item['description'],
                'price' => $item['price'],
                'currency' => 'KSh',
                'billing_period' => 'monthly',
                'memory' => $item['memory'],
                'disk' => $existing?->disk ?: 5120,
                'cpu' => $existing?->cpu ?: 100,
                'databases' => $existing?->databases ?: 1,
                'backups' => $existing?->backups ?: 1,
                'allocations' => $existing?->allocations ?: 1,
                'features' => $item['name'] === 'Unlimited' ? "Unlimited memory\nUnlimited disk\nUnlimited CPU\n30-day renewal" : null,
                'is_featured' => $item['name'] === '4 GB',
                'is_active' => true,
                'sort_order' => $item['sort_order'],
                'updated_at' => $now,
            ];
            if ($existing) {
                DB::table('plans')->where('id', $existing->id)->update($values);
            } else {
                DB::table('plans')->insert($values + [
                    'name' => $item['name'],
                    'created_at' => $now,
                ]);
            }
        }

        // Shiva was an earlier placeholder and is not part of the requested catalog.
        DB::table('plans')->whereRaw('LOWER(name) = ?', ['shiva'])->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Do not delete administrator-managed plans on rollback.
    }
};
