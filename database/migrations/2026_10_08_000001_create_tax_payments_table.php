<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tax actually remitted to the government.
     *
     * The invoice side of the Tax report is derived — it is whatever was
     * billed. This table is the other half: what the company has since paid
     * over, entered by hand. The two together give the balance still owed.
     *
     * `amount` is the authoritative figure. `cgst` and `sgst` are an optional
     * breakdown; when both are filled in they must add up to `amount`, which
     * the controller enforces, so a split can never disagree with the total.
     *
     * `fy` is derived from payment_date on save (April-March, matching
     * InvoiceBuilder::financialYear) so a year's payments can be totalled
     * without date arithmetic in every query.
     */
    public function up(): void
    {
        Schema::create('tax_payments', function (Blueprint $table) {
            $table->id();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('cgst', 12, 2)->default(0);
            $table->decimal('sgst', 12, 2)->default(0);
            // Challan / CIN or whatever reference the bank gave.
            $table->string('reference_no', 100)->nullable();
            $table->string('payment_mode', 50)->nullable();
            $table->string('fy', 7)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            // The report always filters live rows by date or year.
            $table->index(['is_deleted', 'payment_date'], 'tax_payments_live_date_idx');
            $table->index(['is_deleted', 'fy'], 'tax_payments_live_fy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_payments');
    }
};
