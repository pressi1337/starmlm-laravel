<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->text('processing_details')->nullable()->after('reason');
            $table->text('completed_details')->nullable()->after('processing_details');
            $table->text('rejected_details')->nullable()->after('completed_details');
            $table->timestamp('status_updated_at')->nullable()->after('rejected_details');
            $table->unsignedBigInteger('status_updated_by')->nullable()->after('status_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->dropColumn([
                'processing_details',
                'completed_details',
                'rejected_details',
                'status_updated_at',
                'status_updated_by',
            ]);
        });
    }
};
