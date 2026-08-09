<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('gateway', 30)->default('paystack')->after('status');
            $table->string('gateway_reference')->nullable()->after('reference');
            $table->index(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['gateway', 'gateway_reference']);
            $table->dropColumn(['gateway', 'gateway_reference']);
        });
    }
};
