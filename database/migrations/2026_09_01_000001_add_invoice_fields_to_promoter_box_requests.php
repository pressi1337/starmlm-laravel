<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pricing captured when the admin marks a batch Sent, plus the invoice
     * number issued when it is delivered.
     *
     *   rate_per_qty — pre-tax unit price; qty * rate is the taxable amount.
     *   mrp          — printed on the invoice for reference only, never in the
     *                  tax maths.
     *   invoice_no   — sequential, allocated at delivery (max + 1) and unique.
     *
     * All nullable: batches sent before this have no pricing, and a batch that
     * has not been delivered has no invoice number yet.
     */
    public function up(): void
    {
        Schema::table('promoter_box_requests', function (Blueprint $table) {
            $table->decimal('rate_per_qty', 10, 2)->nullable()->after('courier_number');
            $table->decimal('mrp', 10, 2)->nullable()->after('rate_per_qty');
            $table->unsignedInteger('invoice_no')->nullable()->after('mrp');

            $table->unique('invoice_no', 'pbr_invoice_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('promoter_box_requests', function (Blueprint $table) {
            $table->dropUnique('pbr_invoice_no_unique');
            $table->dropColumn(['rate_per_qty', 'mrp', 'invoice_no']);
        });
    }
};
