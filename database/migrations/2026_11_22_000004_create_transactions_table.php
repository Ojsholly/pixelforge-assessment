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
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('transactionable');
            $table->string('direction', 16);
            $table->string('type', 24);
            $table->bigInteger('amount')->comment('Positive amount in nanodollars (1 USD = 1,000,000,000 nanodollars)');
            $table->bigInteger('balance_after')->comment('Wallet balance in nanodollars after transaction');
            $table->string('description', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['direction', 'type']);
            $table->unique(['wallet_id', 'transactionable_type', 'transactionable_id', 'type'], 'transactions_wallet_morph_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
