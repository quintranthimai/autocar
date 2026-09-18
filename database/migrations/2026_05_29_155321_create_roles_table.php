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
    Schema::create('roles', function (Blueprint $table) {
        $table->bigIncrements('id'); // Primary Key [cite: 444]
        $table->string('name', 50)->unique(); // VD: Admin, Renter [cite: 444]
        $table->string('slug', 50)->unique(); // VD: ops_admin [cite: 444]
        $table->string('description', 255)->nullable(); // VD: Administrator with full privileges [cite: 444]
        $table->timestamps(); // VD: Created at, Updated at [cite: 444]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
