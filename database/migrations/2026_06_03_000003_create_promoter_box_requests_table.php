<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promoter box (product) allocation + delivery tracking.
     *
     * Each row is one box "batch" a promoter receives at a level. Levels 0/1/2
     * get a fixed default quantity auto-created at pin activation (1/1/10);
     * levels 3/4 pick a quantity (options 10/20 and 10/20/30) up to a
     * cumulative per-level cap (20 and 30) and may request more later. Every
     * batch flows Requested -> Sent (admin) -> Delivered (user). Per-request
     * delivery details are captured here (not inherited).
     *
     * Net-new: pre-existing promoters simply have no rows here.
     */
    public function up(): void
    {
        Schema::create('promoter_box_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('user_promoter_id')->nullable(); // activation cycle, when known
            $table->tinyInteger('level')->nullable();                   // promoter level at request time (0..4)
            $table->integer('quantity')->default(0);                    // number of boxes in this batch

            // Per-request delivery details (1 = pickup, 2 = delivery).
            $table->tinyInteger('delivery_type')->nullable();
            $table->text('delivery_address')->nullable();
            $table->date('pickup_date')->nullable();
            $table->string('contact_number')->nullable();

            // 1 = requested, 2 = sent (admin), 3 = delivered (user)
            $table->tinyInteger('status')->default(1);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'level'], 'pbr_user_level_idx');
            $table->index(['status'], 'pbr_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promoter_box_requests');
    }
};
