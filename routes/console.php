<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('promoters:process-pending', function () {
    $now = now();

    DB::table('user_promoters')
        ->where('is_deleted', 0)
        ->where('status', 0)
        ->whereNull('term_raised_at')
        ->where('created_at', '<=', $now->copy()->subMinutes(10))
        ->update([
            'term_raised_at' => $now,
            'updated_at' => $now,
        ]);

    DB::table('user_promoters')
        ->where('is_deleted', 0)
        ->where('status', 0)
        ->where('created_at', '<=', $now->copy()->subDays(3))
        ->update([
            'status' => 4,
            'is_active' => 0,
            'is_deleted' => 1,
            'auto_deleted_at' => $now,
            'deleted_reason' => 'Automatically deleted after 3 days without admin action',
            'updated_at' => $now,
        ]);

    $this->info('Pending promoter requests processed successfully.');
})->purpose('Automatically raise terms and expire old pending promoter requests');

Schedule::command('promoters:process-pending')->everyFiveMinutes();
