<?php

namespace App\Services;

use App\Models\WithdrawRequest;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads an edited withdraw export back in and works out, row by row, what it
 * would do — without doing any of it.
 *
 * The admin downloads the withdraw Excel, sets Status on each row (and a
 * Reason where they are rejecting), and uploads it again. This class turns
 * that file into a verdict per row so the screen can show exactly what will
 * happen before anything is committed. Applying the result is the
 * controller's job; nothing here writes.
 *
 * The same analysis runs again at confirm time, so a request whose status
 * changed between the two steps is caught rather than silently overwritten.
 */
class WithdrawImport
{
    /** Statuses an admin may set from the sheet. Pending is deliberately not
     *  offered — moving a request back to Pending is not a real operation. */
    public const SETTABLE_STATUSES = [
        'processing' => WithdrawRequest::STATUS_PROCESSING,
        'complete'   => WithdrawRequest::STATUS_COMPLETED,
        'completed'  => WithdrawRequest::STATUS_COMPLETED,
        'reject'     => WithdrawRequest::STATUS_REJECTED,
        'rejected'   => WithdrawRequest::STATUS_REJECTED,
    ];

    /** How every status reads back to a human. */
    public const STATUS_LABELS = [
        WithdrawRequest::STATUS_PENDING    => 'Pending',
        WithdrawRequest::STATUS_PROCESSING => 'Processing',
        WithdrawRequest::STATUS_COMPLETED  => 'Completed',
        WithdrawRequest::STATUS_REJECTED   => 'Rejected',
    ];

    /**
     * Statuses that are the end of the road. Re-applying one would run the
     * rejection refund a second time, so these are refused, not repeated.
     */
    public const FINAL_STATUSES = [
        WithdrawRequest::STATUS_COMPLETED,
        WithdrawRequest::STATUS_REJECTED,
    ];

    public const COL_REQUEST_ID = 'request id';
    public const COL_STATUS = 'status';
    public const COL_REASON = 'reason';

    /** A row that will be applied. */
    public const ACTION_UPDATE = 'update';
    /** Valid, but the status already matches — applying it would change nothing. */
    public const ACTION_UNCHANGED = 'unchanged';
    /** Something is wrong with the row; it blocks the whole upload. */
    public const ACTION_ERROR = 'error';

    /**
     * Turn an uploaded file into a per-row verdict.
     *
     * Throws only when the FILE itself is unusable (unreadable, or missing the
     * columns we need) — a problem with the upload rather than with its
     * contents. Everything else comes back as a row-level issue.
     *
     * @return array{rows: array, summary: array, can_confirm: bool}
     */
    public function analyse(UploadedFile $file): array
    {
        [$headerRowIndex, $columns, $sheet] = $this->locateColumns($file);

        $rows = [];
        $seenIds = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($r = $headerRowIndex + 1; $r <= $highestRow; $r++) {
            $rawId = $this->cell($sheet, $columns[self::COL_REQUEST_ID], $r);
            $rawStatus = $this->cell($sheet, $columns[self::COL_STATUS] ?? null, $r);
            $rawReason = $this->cell($sheet, $columns[self::COL_REASON] ?? null, $r);

            // A completely blank line is padding, not a row the admin meant.
            if ($rawId === '' && $rawStatus === '' && $rawReason === '') {
                continue;
            }

            $rows[] = $this->analyseRow($r, $rawId, $rawStatus, $rawReason, $seenIds);
        }

        return $this->withSummary($rows);
    }

    private function analyseRow(int $rowNumber, string $rawId, string $rawStatus, string $rawReason, array &$seenIds): array
    {
        $row = [
            'row_number' => $rowNumber,
            'request_id' => $rawId,
            'username' => null,
            'amount' => null,
            'current_status' => null,
            'new_status' => trim($rawStatus),
            'reason' => trim($rawReason),
            'issues' => [],
            'action' => self::ACTION_ERROR,
        ];

        if ($rawId === '' || !ctype_digit(ltrim($rawId, '0')) && !ctype_digit($rawId)) {
            $row['issues'][] = 'Request ID is missing or not a number';
            return $row;
        }

        $id = (int) $rawId;
        $row['request_id'] = $id;

        // Same request twice in one file: whichever won would be arbitrary.
        if (isset($seenIds[$id])) {
            $row['issues'][] = 'Request ID ' . $id . ' also appears on row ' . $seenIds[$id];
            return $row;
        }
        $seenIds[$id] = $rowNumber;

        $withdraw = WithdrawRequest::with('user')->where('id', $id)->where('is_deleted', 0)->first();
        if (!$withdraw) {
            $row['issues'][] = 'No withdraw request with this ID';
            return $row;
        }

        $row['username'] = $withdraw->user->username ?? null;
        $row['amount'] = (float) $withdraw->amount;
        $currentStatus = (int) $withdraw->status;
        $row['current_status'] = self::STATUS_LABELS[$currentStatus] ?? 'Unknown';

        $statusKey = strtolower(trim($rawStatus));
        if ($statusKey === '') {
            $row['issues'][] = 'Status is empty — set Processing, Completed or Rejected';
            return $row;
        }
        if (!array_key_exists($statusKey, self::SETTABLE_STATUSES)) {
            $row['issues'][] = 'Status "' . trim($rawStatus) . '" is not one of Processing, Completed or Rejected';
            return $row;
        }
        $newStatus = self::SETTABLE_STATUSES[$statusKey];
        $row['new_status'] = self::STATUS_LABELS[$newStatus];

        // Already finished: re-applying would refund a rejection twice.
        if (in_array($currentStatus, self::FINAL_STATUSES, true)) {
            $row['issues'][] = 'Already ' . self::STATUS_LABELS[$currentStatus]
                . ' — this record can no longer be updated';
            return $row;
        }

        if ($newStatus === WithdrawRequest::STATUS_REJECTED && $row['reason'] === '') {
            $row['issues'][] = 'Reason is required when the status is Rejected';
            return $row;
        }

        $row['action'] = $currentStatus === $newStatus
            ? self::ACTION_UNCHANGED
            : self::ACTION_UPDATE;

        return $row;
    }

    /** Roll the per-row verdicts up into counts the screen can show. */
    private function withSummary(array $rows): array
    {
        $errors = 0;
        $ready = 0;
        $unchanged = 0;
        foreach ($rows as $row) {
            if ($row['action'] === self::ACTION_ERROR) {
                $errors++;
            } elseif ($row['action'] === self::ACTION_UNCHANGED) {
                $unchanged++;
            } else {
                $ready++;
            }
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'ready' => $ready,
                'unchanged' => $unchanged,
                'errors' => $errors,
            ],
            // Nothing is applied while a single row is wrong: a half-applied
            // batch of money movements is far worse than making them re-upload.
            'can_confirm' => $errors === 0 && $ready > 0,
        ];
    }

    /**
     * Find the heading row and the columns we care about.
     *
     * Columns are matched by NAME, not position, so hiding or reordering
     * columns in Excel — or exporting a different set later — does not
     * silently read the wrong field.
     *
     * @return array{0:int, 1:array, 2:\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet}
     */
    private function locateColumns(UploadedFile $file): array
    {
        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable $e) {
            throw new \RuntimeException('That file could not be read. Upload the .xlsx exported from this page.');
        }

        $highestRow = min($sheet->getHighestDataRow(), 20); // heading is near the top
        $highestColumn = $sheet->getHighestDataColumn();

        for ($r = 1; $r <= $highestRow; $r++) {
            $found = [];
            foreach ($sheet->getRowIterator($r, $r) as $row) {
                $cells = $row->getCellIterator('A', $highestColumn);
                $cells->setIterateOnlyExistingCells(false);
                foreach ($cells as $cell) {
                    $heading = strtolower(trim((string) $cell->getValue()));
                    if ($heading !== '') {
                        $found[$heading] = $cell->getColumn();
                    }
                }
            }

            if (isset($found[self::COL_REQUEST_ID]) && isset($found[self::COL_STATUS])) {
                return [$r, $found, $sheet];
            }
        }

        throw new \RuntimeException(
            'Could not find the "Request ID" and "Status" columns. Upload the .xlsx exported from this page, with those headings left as they are.'
        );
    }

    private function cell($sheet, ?string $column, int $row): string
    {
        if ($column === null) {
            return '';
        }

        return trim((string) $sheet->getCell($column . $row)->getValue());
    }
}
