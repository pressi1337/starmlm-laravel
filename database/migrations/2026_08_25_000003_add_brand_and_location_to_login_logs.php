<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the login log readable at a glance.
     *
     *   device_brand        — "Samsung" / "Xiaomi" / "Oppo", worked out from the
     *                         model code in the user agent. "SM-G991B" means
     *                         nothing to a person reading the list; "Samsung
     *                         SM-G991B" does.
     *   city/region/country — resolved from the IP address, filled in the first
     *                         time the admin views that row and then kept, so
     *                         the same IP is never looked up twice.
     *   isp                 — who the connection belongs to (Jio, Airtel, a
     *                         broadband provider). This is what explains a
     *                         shared IP: mobile carriers put thousands of
     *                         customers behind one address.
     *   location_checked_at — stamped once the lookup has run, successfully or
     *                         not, so failed rows aren't retried on every page
     *                         view. NULL = not looked up yet.
     */
    public function up(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->string('device_brand', 40)->nullable()->after('device_model');
            $table->string('city', 80)->nullable()->after('ip_address');
            $table->string('region', 80)->nullable()->after('city');
            $table->string('country', 80)->nullable()->after('region');
            $table->string('isp', 120)->nullable()->after('country');
            $table->timestamp('location_checked_at')->nullable()->after('isp');

            // The backfill looks up "rows on this IP that aren't resolved yet".
            $table->index(['ip_address', 'location_checked_at'], 'login_logs_ip_geo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->dropIndex('login_logs_ip_geo_idx');
            $table->dropColumn([
                'device_brand',
                'city',
                'region',
                'country',
                'isp',
                'location_checked_at',
            ]);
        });
    }
};
