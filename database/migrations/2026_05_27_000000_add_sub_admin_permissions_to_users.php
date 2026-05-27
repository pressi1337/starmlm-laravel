<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_daily_videos')->default(false)->after('role');
            $table->boolean('can_promotion_videos')->default(false)->after('can_daily_videos');
            $table->boolean('can_pin_requests')->default(false)->after('can_promotion_videos');
        });

        // Backfill: any existing sub-admin keeps the access they implicitly had
        // before this feature shipped. New sub-admins default to 0/0/0 and the
        // super-admin must explicitly grant at least one permission.
        DB::table('users')
            ->where('role', User::ROLE_SUB_ADMIN)
            ->update([
                'can_daily_videos'     => 1,
                'can_promotion_videos' => 1,
                'can_pin_requests'     => 1,
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_daily_videos', 'can_promotion_videos', 'can_pin_requests']);
        });
    }
};
