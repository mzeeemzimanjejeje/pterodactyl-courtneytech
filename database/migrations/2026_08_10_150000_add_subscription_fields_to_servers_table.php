<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('subscription_expires_at')->nullable()->after('installed_at');
            $table->decimal('subscription_price', 10, 2)->nullable()->after('subscription_expires_at');
            $table->boolean('subscription_exempt')->default(false)->after('subscription_price');
            $table->index(['subscription_expires_at', 'subscription_exempt']);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['subscription_expires_at', 'subscription_exempt']);
            $table->dropColumn(['subscription_expires_at', 'subscription_price', 'subscription_exempt']);
        });
    }
};

