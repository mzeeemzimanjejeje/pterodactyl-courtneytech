<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('server_purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('reference', 191)->unique();
            $table->string('gateway', 32);
            $table->string('gateway_reference', 191)->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('KES');
            $table->string('status', 32)->default('pending')->index();
            $table->json('payload');
            $table->unsignedInteger('server_id')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_purchase_payments');
    }
};
