<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: allow system-level transactions not tied to a wallet
        DB::statement('ALTER TABLE transactions ALTER COLUMN wallet_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Best-effort rollback; will fail if there are NULL wallet_id rows
        DB::statement('ALTER TABLE transactions ALTER COLUMN wallet_id SET NOT NULL');
    }
};
