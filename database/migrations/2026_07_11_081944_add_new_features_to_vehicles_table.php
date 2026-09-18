<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 1. Nới lỏng rành buộc cho Số khung, Số máy (Vì UI chưa có)
            $table->string('vin_number')->nullable()->change();
            $table->string('engine_number')->nullable()->change();

            // 2. Bổ sung các cột cho tính năng Giao xe & Giới hạn KM & Giảm giá
            $table->integer('free_delivery_radius_km')->default(0)->after('delivery_fee_per_km');
            
            $table->boolean('is_discount_enabled')->default(false)->after('base_price');
            $table->integer('weekly_discount_percent')->default(0)->after('is_discount_enabled');
            
            $table->boolean('is_mileage_limit_enabled')->default(false)->after('free_delivery_radius_km');
            $table->integer('mileage_limit_per_day')->nullable()->after('is_mileage_limit_enabled');
            $table->decimal('extra_fee_per_km', 10, 2)->nullable()->after('mileage_limit_per_day');
            
            $table->text('rental_terms')->nullable()->after('status');
        });
    }

    public function down()
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'free_delivery_radius_km', 
                'is_discount_enabled', 
                'weekly_discount_percent',
                'is_mileage_limit_enabled',
                'mileage_limit_per_day',
                'extra_fee_per_km',
                'rental_terms'
            ]);
        });
    }
};