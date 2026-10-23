<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * A mobile number must be exactly 10 digits.
 *
 * This is the one definition of that rule. Every path that writes
 * `users.mobile` validates through it, so registration, the admin referral
 * form and the profile-edit screen can never drift apart again — a name, a
 * landline or a 9-digit typo is rejected identically everywhere.
 *
 * Use it together with normalize(): people paste "+91 98765-43210" or
 * "098765 43210" from their contacts, which is the same number with decoration
 * around it. Normalising first means those are accepted and stored in one
 * canonical shape, while genuine rubbish still fails.
 */
class MobileNumber implements Rule
{
    public const LENGTH = 10;

    /**
     * Strip the decoration people paste in and return the bare national
     * number, so validation judges the digits rather than the formatting.
     *
     * Leaves anything it cannot confidently reduce alone — it is the rule's
     * job to reject, not this method's, which must never silently turn bad
     * input into something that passes.
     */
    public static function normalize($value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Strip formatting only — spaces, dashes, dots, brackets and a leading
        // plus. Letters are deliberately NOT stripped: doing so would reduce
        // "Suresh9876543210" to a number that passes, which is precisely the
        // bug this rule exists to stop. Anything still containing a non-digit
        // is handed back untouched for the rule to reject.
        $digits = preg_replace('/[\s()\-.\/]|^\+/u', '', trim((string) $value));
        if ($digits === null || $digits === '' || preg_match('/\D/', $digits)) {
            return is_string($value) ? trim($value) : $value;
        }

        // India country code, with or without the trunk 0 in front of it.
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '091')) {
            $digits = substr($digits, 3);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            // Trunk prefix dialled before a 10-digit mobile.
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    public function passes($attribute, $value)
    {
        return is_string($value) || is_numeric($value)
            ? (bool) preg_match('/^\d{' . self::LENGTH . '}$/', (string) $value)
            : false;
    }

    public function message()
    {
        return 'Mobile number must be exactly ' . self::LENGTH . ' digits.';
    }
}
