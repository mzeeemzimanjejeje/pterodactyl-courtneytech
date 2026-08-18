<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('plan_id')->nullable()->after('owner_id');
            $table->decimal('renewal_price', 10, 2)->nullable()->after('installed_at');
            $table->timestamp('next_renewal_at')->nullable()->after('renewal_price');
            $table->boolean('renewal_enabled')->default(true)->after('next_renewal_at');
            $table->index(['owner_id', 'next_renewal_at']);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'next_renewal_at']);
            $table->dropColumn(['plan_id', 'renewal_price', 'next_renewal_at', 'renewal_enabled']);
        });
    }
};
