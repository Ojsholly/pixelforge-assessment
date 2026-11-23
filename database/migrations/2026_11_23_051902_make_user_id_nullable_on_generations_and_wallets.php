<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing records must survive account deletion for admin reconciliation:
 * User::deleting anonymizes generations and the wallet (scrubs PII, nulls
 * user_id) instead of letting the cascade destroy financial history.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Anonymized billing rows have no owner to restore the NOT NULL
        // constraint onto — fail with guidance instead of a constraint error.
        $orphanedGenerations = DB::table('generations')->whereNull('user_id')->count();
        $orphanedWallets = DB::table('wallets')->whereNull('user_id')->count();

        if ($orphanedGenerations > 0 || $orphanedWallets > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$orphanedGenerations} generation(s) and {$orphanedWallets} wallet(s) ".
                'are retained anonymized billing records (user_id is null). Reassign them to an owner '.
                'or delete them manually before restoring the non-null constraint.'
            );
        }

        Schema::table('generations', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('wallets', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
