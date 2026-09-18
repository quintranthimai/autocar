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
    Schema::create('role_user', function (Blueprint $table) {
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // VD: Foreign key to users table [cite: 446]
        $table->foreignId('role_id')->constrained('roles')->onDelete('cascade'); // VD: Foreign key to roles table [cite: 446]
        $table->primary(['user_id', 'role_id']); // Composite primary key [cite: 446]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
