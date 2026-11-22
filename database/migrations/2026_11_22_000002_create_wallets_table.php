<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance')->default(0)->comment('Balance in nanodollars (1 USD = 1,000,000,000 nanodollars)');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');
        }

        $now = now()->toDateTimeString();

        DB::insert(
            'INSERT INTO wallets (user_id, balance, created_at, updated_at)
             SELECT id, 0, ?, ? FROM users
             WHERE NOT EXISTS (SELECT 1 FROM wallets WHERE wallets.user_id = users.id)',
            [$now, $now],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
