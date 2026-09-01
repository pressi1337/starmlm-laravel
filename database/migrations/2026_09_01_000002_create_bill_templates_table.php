<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The seller ("Billed by") side of the plan-product invoice, plus the
     * invoice-wide settings.
     *
     * Single-row pattern, like terms_and_conditions: the admin edits one
     * record and every invoice renders from it. Keeping it in its own table
     * rather than a key/value store means the fields are typed, validated in
     * one place, and obvious to anyone reading the schema.
     *
     * The buyer side is read live from `users` — there is no GSTIN or PAN
     * collected for users, so those lines never appear on the "Billed to" block.
     */
    public function up(): void
    {
        Schema::create('bill_templates', function (Blueprint $table) {
            $table->id();

            // Billed by
            $table->string('company_name');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('pincode', 20)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();

            // Invoice settings. Tax is fixed at 18% (9 CGST + 9 SGST) in the
            // builder; only the presentation bits are configurable here.
            $table->string('invoice_prefix', 20)->nullable();
            $table->string('hsn_code', 20)->nullable();
            $table->string('place_of_supply', 120)->nullable();
            $table->string('country_of_supply', 120)->default('India');

            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_templates');
    }
};
