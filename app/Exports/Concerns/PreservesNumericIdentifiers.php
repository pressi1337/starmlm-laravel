<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Keeps identifier-like numeric strings (bank account numbers, long IDs,
 * leading-zero values) intact in Excel exports.
 *
 * Why this is needed: a numeric *cell* in Excel only retains 15 significant
 * digits, so a 16-digit account number such as 1731155000030815 is silently
 * rounded to 1731155000030810; leading zeros are likewise dropped. A number
 * format alone ("@" text format) does NOT fix this — it only changes display
 * while the value is still stored as a number and already truncated. The cell
 * must be written as a string *type*, which is what this value binder does.
 *
 * Used by export classes that `extend DefaultValueBinder implements
 * WithCustomValueBinder`. Short numeric values (ids, amounts, counts) fall
 * through to the parent binder and stay numeric.
 */
trait PreservesNumericIdentifiers
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && $value !== '' && (
            preg_match('/^\d{16,}$/', $value) === 1   // 16+ digit numbers lose precision
            || preg_match('/^0\d+$/', $value) === 1   // leading-zero numbers lose the zero
        )) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
