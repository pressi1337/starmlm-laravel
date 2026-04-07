<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_promoters', function (Blueprint $table) {
            $table->tinyInteger('product_delivery_status')->default(0)->after('deleted_reason');
            $table->text('product_delivery_notes')->nullable()->after('product_delivery_status');
            $table->string('bill_path')->nullable()->after('product_delivery_notes');
            $table->timestamp('product_delivery_updated_at')->nullable()->after('bill_path');
            $table->tinyInteger('customer_delivery_status')->default(0)->after('product_delivery_updated_at');
            $table->timestamp('customer_delivery_confirmed_at')->nullable()->after('customer_delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_promoters', function (Blueprint $table) {
            $table->dropColumn([
                'product_delivery_status',
                'product_delivery_notes',
                'bill_path',
                'product_delivery_updated_at',
                'customer_delivery_status',
                'customer_delivery_confirmed_at',
            ]);
        });
    }
};
