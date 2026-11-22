<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id', 64)->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 24)->default('queued');
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images')->nullable();
            $table->json('rates_snapshot');
            $table->json('estimated_tokens');
            $table->bigInteger('estimated_min_user_cost')->comment('Min estimated user cost in nanodollars');
            $table->bigInteger('estimated_max_user_cost')->comment('Max estimated user cost in nanodollars');
            $table->bigInteger('reserved_amount')->default(0)->comment('Amount currently held in nanodollars');
            $table->json('actual_usage')->nullable();
            $table->bigInteger('actual_provider_cost')->nullable()->comment('Exact Pixelforge cost in nanodollars; 0 on failure');
            $table->bigInteger('actual_user_cost')->nullable()->comment('Exact user charge with 25% markup in nanodollars; 0 on failure');
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
