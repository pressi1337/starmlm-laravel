<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online payments through the Omniware (Federal Bank) gateway.
     *
     * One row per payment ATTEMPT — the gateway rejects a reused order_id, so
     * a retry after a failure is a new row with a new order_id. For now only
     * the admin console's test payments (purpose = 'test') write here; the
     * promoter-plan columns (user_promoter_id, level) are for the later phase.
     */
    public function up(): void
    {
        Schema::create('promoter_payments', function (Blueprint $table) {
            $table->id();
            // promoter = plan payment; test = admin test payment (no plan).
            $table->string('purpose', 20)->default('promoter');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('user_promoter_id')->nullable();
            $table->tinyInteger('level')->nullable();
            // Our merchant reference sent to the gateway — varchar(30) there.
            $table->string('order_id', 30)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('mode', 4)->default('TEST');
            // 0 initiated, 1 success, 2 failed, 3 cancelled (PromoterPayment::STATUS_*)
            $table->tinyInteger('status')->default(0);

            // Hosted payment page (two-step integration).
            $table->string('gateway_uuid', 64)->nullable();
            $table->string('payment_url', 500)->nullable();
            $table->dateTime('url_expires_at')->nullable();
            // Admin test payments: console origin to send the browser back to.
            $table->string('redirect_to', 255)->nullable();
            // What we sent to the gateway (without the hash) — for debugging.
            $table->json('request_payload')->nullable();

            // Result, as reported by the gateway.
            $table->string('transaction_id', 64)->nullable();
            $table->integer('response_code')->nullable();
            $table->string('response_message', 255)->nullable();
            $table->string('error_desc', 500)->nullable();
            $table->string('payment_mode', 100)->nullable();
            $table->string('payment_channel', 100)->nullable();
            $table->string('payment_datetime', 30)->nullable();
            // return | callback | status_api — which channel settled the row.
            $table->string('settled_via', 20)->nullable();
            // 1 = response hash matched, 0 = mismatch, null = not checked (Status API).
            $table->tinyInteger('hash_verified')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->json('gateway_response')->nullable();

            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['user_promoter_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['purpose', 'created_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('promoter_payments');
    }
};
