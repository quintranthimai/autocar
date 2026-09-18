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
        Schema::table('wallets', function (Blueprint $table) {
            $table->text('locked_reason')->nullable()->after('status');
            $table->timestamp('locked_at')->nullable()->after('locked_reason');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('status', 20)->default('success')->after('description');
            $table->timestamp('release_at')->nullable()->after('status');
            $table->unsignedBigInteger('created_by')->nullable()->after('release_at');
            $table->string('reference_type')->nullable()->after('created_by');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            $table->string('txn_ref', 50)->nullable()->after('reference_id');
            $table->unsignedBigInteger('payment_gateway_id')->nullable()->after('txn_ref');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'release_at', 'created_by', 'reference_type', 'reference_id', 'txn_ref', 'payment_gateway_id'
            ]);
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['locked_reason', 'locked_at']);
        });
    }
};
