<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ReferralScratchLevel;

class ReferralScratchLevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $auth_user_id = 1; // Replace with the appropriate user ID or logic to get the admin user

        $levels = [
            ['promotor_level' => 0, 'is_active' => 1, 'is_deleted' => 0, 'created_by' => $auth_user_id, 'updated_by' => $auth_user_id],
            ['promotor_level' => 1, 'is_active' => 1, 'is_deleted' => 0, 'created_by' => $auth_user_id, 'updated_by' => $auth_user_id],
            ['promotor_level' => 2, 'is_active' => 1, 'is_deleted' => 0, 'created_by' => $auth_user_id, 'updated_by' => $auth_user_id],
            ['promotor_level' => 3, 'is_active' => 1, 'is_deleted' => 0, 'created_by' => $auth_user_id, 'updated_by' => $auth_user_id],
            ['promotor_level' => 4, 'is_active' => 1, 'is_deleted' => 0, 'created_by' => $auth_user_id, 'updated_by' => $auth_user_id],
        ];

        foreach ($levels as $level) {
            ReferralScratchLevel::updateOrCreate(
                ['promotor_level' => $level['promotor_level']],
                $level
            );
        }
    }
}
