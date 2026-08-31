<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a plan-product batch was dispatched, captured when the admin marks
     * it Sent.
     *
     *   Direct  -> the user collected it in person; we record the date.
     *   Courier -> we record which courier and the tracking number.
     *
     * All nullable: batches sent before this existed have no dispatch details,
     * and a batch still Requested has none yet.
     */
    public function up(): void
    {
        Schema::table('promoter_box_requests', function (Blueprint $table) {
            // 1 = direct/collected, 2 = courier (PromoterBoxRequest::DISPATCH_*)
            $table->tinyInteger('dispatch_method')->nullable()->after('sent_by');
            $table->date('collected_date')->nullable()->after('dispatch_method');
            $table->string('courier_name', 120)->nullable()->after('collected_date');
            $table->string('courier_number', 120)->nullable()->after('courier_name');
        });
    }

    public function down(): void
    {
        Schema::table('promoter_box_requests', function (Blueprint $table) {
            $table->dropColumn([
                'dispatch_method',
                'collected_date',
                'courier_name',
                'courier_number',
            ]);
        });
    }
};
