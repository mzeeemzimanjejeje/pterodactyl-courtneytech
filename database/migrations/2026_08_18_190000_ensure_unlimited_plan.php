<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        $values = [
            'egg_id' => $egg->id,
            'nest_id' => $egg->nest_id,
            'description' => 'Unlimited panel access with 30-day billing.',
            'price' => 150.00,
            'currency' => 'KSh',
            'billing_period' => 'monthly',
            'memory' => 0,
            'disk' => 0,
            'cpu' => 0,
            'databases' => 0,
            'backups' => 0,
            'allocations' => 1,
            'is_featured' => true,
            'is_active' => true,
            'sort_order' => -100,
            'updated_at' => now(),
        ];

        $existing = DB::table('plans')->whereRaw('LOWER(name) = ?', ['unlimited'])->first(['id']);
        if ($existing) {
            DB::table('plans')->where('id', $existing->id)->update($values);
            return;
        }

        DB::table('plans')->insert($values + [
            'name' => 'Unlimited',
            'features' => "Unlimited memory\nUnlimited disk\nUnlimited CPU\n30-day renewal",
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Do not remove an administrator-created Unlimited plan on rollback.
    }
};
