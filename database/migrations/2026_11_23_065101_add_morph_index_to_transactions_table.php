<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The existing unique index leads with wallet_id, so morph-target
     * lookups (transactionable_type + transactionable_id) — used when an
     * orphaned generation settles through its hold transaction — cannot
     * use it. This composite covers that query path.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(['transactionable_type', 'transactionable_id'], 'transactions_morph_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_morph_index');
        });
    }
};
