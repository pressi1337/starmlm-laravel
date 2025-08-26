<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = [
            [
                'name' => 'Admin',
                'mobile' => '9876543210',
                'role' => '0',
                'mobile_verified' => '1',
                'password' => Hash::make('12345678'),
                'pwd_text' => '12345678',
            ],
            [
                'name' => 'TESTUSER1',
                'mobile' => '9876543211',
                'role' => '2',
                'mobile_verified' => '1',
                'password' => Hash::make('12345678'),
                'pwd_text' => '12345678',
            ],
        ];

        foreach ($user as $key => $value) {
            User::create($value);
        }
    }
}
