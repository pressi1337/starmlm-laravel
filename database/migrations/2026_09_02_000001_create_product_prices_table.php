<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product price master — one row per promoter level.
     *
     * The admin sets the sales price and MRP for each level once, and every
     * dispatch at that level bills at that price. It replaces the admin typing
     * a rate into the mark-as-sent form, so two batches at the same level can
     * never be billed differently by accident.
     *
     * NOTE: promoter_box_requests.rate_per_qty / mrp remain, and are now
     * SNAPSHOTS copied from here when the batch is dispatched. That is
     * deliberate — an invoice is a tax document, so editing this master must
     * never change what an already-issued invoice says.
     */
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            // Promoter level 0-4 (see PromoterBoxRequest::LEVEL_RULES).
            $table->tinyInteger('level');
            $table->string('product_name', 120);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('mrp', 10, 2)->default(0);
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One live price per level.
            $table->unique(['level', 'is_deleted'], 'product_prices_level_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
