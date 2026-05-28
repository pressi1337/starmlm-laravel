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
            // Public-facing customer reference. ROLE_USER (2) only — admins
            // and sub-admins keep it NULL. Auto-generated on register; never
            // editable from the UI. Unique to prevent any backfill / race
            // collisions; size 20 leaves headroom past STARUP9999.
            $table->string('customer_id', 20)->nullable()->unique()->after('username');
        });

        // Backfill: assign STARUP001, STARUP002, ... to existing role=2 rows
        // in id-asc order so the earliest registered user gets STARUP001.
        $users = DB::table('users')
            ->where('role', User::ROLE_USER)
            ->whereNull('customer_id')
            ->orderBy('id', 'asc')
            ->pluck('id');

        $counter = 1;
        foreach ($users as $id) {
            DB::table('users')
                ->where('id', $id)
                ->update([
                    'customer_id' => 'STARUP' . str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
                ]);
            $counter++;
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
