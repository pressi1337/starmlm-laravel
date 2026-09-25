<?php

namespace App\Exports;

use App\Exports\Concerns\PreservesNumericIdentifiers;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WithdrawRequestExport extends DefaultValueBinder implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithCustomValueBinder, WithEvents
{
    use PreservesNumericIdentifiers;

    /**
     * Processing fee held back from a withdrawal, as a percentage.
     *
     * Reporting only — nothing in the withdrawal flow stores or deducts a fee,
     * so this is derived for the sheet rather than read from the request. If
     * the fee ever becomes something the system actually deducts, it belongs
     * on withdraw_requests and this constant should go.
     */
    public const PROCESSING_FEE_PERCENT = 10.0;

    protected $withdrawRequests;

    public function __construct($withdrawRequests)
    {
        $this->withdrawRequests = $withdrawRequests;
    }

    public function collection()
    {
        return $this->withdrawRequests;
    }

    public function headings(): array
    {
        return [
            'Request ID',
            'Username',
            'Full Name',
            'Mobile',
            'Address',
            'Promoter Level',
            'Bank Name',
            'Account Number',
            'IFSC Code',
            'Branch Name',
            'Request Date',
            'Status',
            'Amount',
            'Processing Fee (' . rtrim(rtrim(number_format(self::PROCESSING_FEE_PERCENT, 2), '0'), '.') . '%)',
            'Withdrawable Amount',
            // The two columns the admin fills in. "Status" above stays as it
            // is — the current status, for reference only. This one ships
            // EMPTY with a dropdown, so the only thing they can do is pick
            // one of the three options; a row left blank is left alone.
            'New Status',
            'Reason',
        ];
    }

    public function map($withdrawRequest): array
    {
        $statuses = [
            0 => 'Pending',
            1 => 'Processing',
            2 => 'Completed',
            3 => 'Rejected'
        ];
        $promoter_levels = [
            0 => 'Promoter',
            1 => 'Promoter1',
            2 => 'Promoter2',
            3 => 'Promoter3',
            4 => 'Promoter4'
        ];

        // Amount stays exactly what the user asked for. The fee is rounded
        // first and the payable derived by subtraction, so Fee + Withdrawable
        // always equals Amount to the paisa — rounding both independently
        // would leave rows that are a paisa out and never reconcile.
        $amount = round((float) $withdrawRequest->amount, 2);
        $fee = round($amount * self::PROCESSING_FEE_PERCENT / 100, 2);
        $withdrawable = round($amount - $fee, 2);

        return [
            $withdrawRequest->id,
            $withdrawRequest->user->username ?? 'N/A',
            ($withdrawRequest->user->first_name ?? '') . ' ' . ($withdrawRequest->user->last_name ?? ''),
            $withdrawRequest->user->mobile ?? 'N/A',
            $this->formatAddress($withdrawRequest->user),
            $promoter_levels[$withdrawRequest->user->current_promoter_level] ?? 'Unknown',
            $withdrawRequest->bankDetail->bank_name ?? 'N/A',
            $withdrawRequest->bankDetail->acc_no ?? 'N/A',
            $withdrawRequest->bankDetail->ifsc_code ?? 'N/A',
            $withdrawRequest->bankDetail->branch_name ?? 'N/A',
            $withdrawRequest->request_at ? date('d-m-Y h:i A', strtotime($withdrawRequest->request_at)) : '-',
            $statuses[$withdrawRequest->status] ?? 'Unknown',
            // Written as real numbers, not number_format strings: a formatted
            // string like "1,234.56" lands in Excel as TEXT, so accounts
            // cannot total the column. The 0.00 cell format below handles
            // display.
            $amount,
            $fee,
            $withdrawable,
            '', // New Status — left empty on purpose; the admin picks it.
            $withdrawRequest->reason ?? '',
        ];
    }

    protected function formatAddress($user)
    {
        $address = [];
        if (!empty($user->address)) $address[] = $user->address;
        if (!empty($user->city)) $address[] = $user->city;
        if (!empty($user->district)) $address[] = $user->district;
        if (!empty($user->state)) $address[] = $user->state;
        if (!empty($user->pin_code)) $address[] = $user->pin_code;
        
        return !empty($address) ? implode(', ', $address) : 'N/A';
    }

    /**
     * The statuses an admin may choose when editing the sheet.
     *
     * Must stay in step with WithdrawImport::SETTABLE_STATUSES — this is what
     * the dropdown offers, that is what the upload accepts. Pending is
     * deliberately absent: moving a request back to Pending is not an
     * operation the system supports.
     */
    public const SETTABLE_STATUS_LABELS = ['Processing', 'Completed', 'Rejected'];

    /**
     * Put a real dropdown on the Status column of the downloaded file.
     *
     * The admin edits this sheet and uploads it back, so the three choices are
     * given to them here rather than left to be typed from memory. Typing
     * anything else is refused by Excel at the point of entry, which is a far
     * better place to catch it than in our validation step afterwards.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $count = is_countable($this->withdrawRequests)
                    ? count($this->withdrawRequests)
                    : collect($this->withdrawRequests)->count();
                $lastRow = $count + 1; // +1 for the heading row

                $statusColumn = $this->columnLetter('New Status');
                if ($lastRow < 2 || $statusColumn === null) {
                    return; // nothing exported, or no column to guard
                }

                $sheet = $event->sheet->getDelegate();
                $reasonColumn = $this->columnLetter('Reason');

                for ($row = 2; $row <= $lastRow; $row++) {
                    $rule = $sheet->getCell($statusColumn . $row)->getDataValidation();
                    $rule->setType(DataValidation::TYPE_LIST);
                    $rule->setErrorStyle(DataValidation::STYLE_STOP);
                    // Blank is allowed and means "do not touch this request".
                    $rule->setAllowBlank(true);
                    $rule->setShowDropDown(true);
                    $rule->setShowErrorMessage(true);
                    $rule->setShowInputMessage(true);
                    $rule->setErrorTitle('Not a valid status');
                    $rule->setError('Pick Processing, Completed or Rejected from the list.');
                    $rule->setPromptTitle('New status');
                    $rule->setPrompt('Pick one to change this request. Leave blank to leave it alone. Rejected also needs a Reason.');
                    // Quoted inline list — no helper sheet to leak into the file.
                    $rule->setFormula1('"' . implode(',', self::SETTABLE_STATUS_LABELS) . '"');
                }

                // Reason is free text, but say what it is for.
                if ($reasonColumn !== null) {
                    $sheet->getComment($reasonColumn . '1')->getText()
                        ->createTextRun('Required when Status is Rejected.');
                }
            },
        ];
    }

    /** Column letter of a heading, so moving columns cannot break the rules. */
    private function columnLetter(string $heading): ?string
    {
        $index = array_search($heading, $this->headings(), true);

        return $index === false ? null : Coordinate::stringFromColumnIndex($index + 1);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D9EAD3']
                ]
            ],
            // Force-text on identifier columns — Excel otherwise interprets
            // long all-digit strings as floats and zeroes their last digits.
            // D = Mobile, H = Account Number, I = IFSC Code.
            'D' => ['numberFormat' => ['formatCode' => '@']],
            'H' => ['numberFormat' => ['formatCode' => '@']],
            'I' => ['numberFormat' => ['formatCode' => '@']],
            // Money columns keep 2-decimal numeric format.
            // M = Amount, N = Processing Fee, O = Withdrawable Amount.
            'M' => ['numberFormat' => ['formatCode' => '0.00']],
            'N' => ['numberFormat' => ['formatCode' => '0.00']],
            'O' => ['numberFormat' => ['formatCode' => '0.00']],
        ];
    }
}
