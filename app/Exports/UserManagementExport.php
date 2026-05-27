<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export for the admin User Management list.
 * Filtered scope (search, level, date range) is applied by the controller —
 * this class only formats the collection.
 */
class UserManagementExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $users;

    public function __construct($users)
    {
        $this->users = $users;
    }

    public function collection()
    {
        return $this->users;
    }

    public function headings(): array
    {
        return [
            'User ID',
            'Username',
            'Full Name',
            'Mobile',
            'Email',
            'Promoter Level',
            'Videos/Day',
            'Daily Current (₹)',
            'Daily Max (₹)',
            'Per Video Current (₹)',
            'Per Video Max (₹)',
            'Language',
            'City',
            'District',
            'State',
            'Pin Code',
            'Referred By',
            'Status',
            'Joined On',
        ];
    }

    public function map($user): array
    {
        $promoterLevels = [
            0 => 'Promoter',
            1 => 'Promoter Level 1',
            2 => 'Promoter Level 2',
            3 => 'Promoter Level 3',
            4 => 'Promoter Level 4',
        ];

        // Ceiling: same math as the on-screen column. Daily multiplier is
        // level-aware (2 videos/day for L0-L2, 4 videos/day for L3-L4).
        $level = $user->current_promoter_level;
        $levelInfo = User::getLevelEarningInfo($level);
        $perVideoCurrent = $level === null
            ? 0.0
            : $user->computeCurrentPerVideoPotential();
        $videosPerDay = User::videosPerDay($level);
        $dailyCurrent = round($perVideoCurrent * $videosPerDay, 2);
        $dailyMax = round(((float) $levelInfo['max']) * $videosPerDay, 2);

        // Distributor rides inline with the Promoter Level cell so it travels
        // *with* the level wherever this column lands — never a standalone
        // column. Matches the on-screen badge-next-to-level rule.
        $levelLabel = $promoterLevels[$user->current_promoter_level] ?? 'Trainee';
        if (((int) ($user->is_distributor ?? 0)) === 1) {
            $levelLabel .= ' (Distributor)';
        }

        return [
            $user->id,
            $user->username ?? '',
            trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            $user->mobile ?? '',
            $user->email ?? '',
            $levelLabel,
            $videosPerDay,
            $dailyCurrent,
            $dailyMax,
            round($perVideoCurrent, 2),
            round((float) $levelInfo['max'], 2),
            $user->language ?? '',
            $user->city ?? '',
            $user->district ?? '',
            $user->state ?? '',
            $user->pin_code ?? '',
            $user->referrer->username ?? '',
            ((int) $user->is_active) === 1 ? 'Active' : 'Inactive',
            $user->created_at ? $user->created_at->format('d-m-Y h:i A') : '-',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D9EAD3'],
                ],
            ],
            // Force-text on identifier columns so Excel doesn't auto-coerce
            // long all-digit strings (Mobile = D; Pin Code = P after the
            // ceiling columns inserted between Promoter Level and Language).
            'D' => ['numberFormat' => ['formatCode' => '@']],
            'P' => ['numberFormat' => ['formatCode' => '@']],
        ];
    }
}
