<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoterBoxRequest extends Model
{
    const STATUS_REQUESTED = 1;
    const STATUS_SENT = 2;
    const STATUS_DELIVERED = 3;

    const DELIVERY_TYPE_PICKUP = 1;
    const DELIVERY_TYPE_DELIVERY = 2;

    // How the batch actually left us, recorded when it is marked Sent.
    // Distinct from DELIVERY_TYPE_*, which is what the user ASKED for at
    // request time; this is what the admin actually did.
    const DISPATCH_DIRECT  = 1;
    const DISPATCH_COURIER = 2;

    /**
     * Per-level box rules:
     *   - cap:     cumulative max boxes a user may receive at that level.
     *   - default: quantity auto-granted at activation (auto levels only).
     *   - options: selectable batch sizes the user picks (manual levels).
     *   - auto:    true => granted automatically at activation, no user choice.
     *
     * Levels 0/1/2 are auto (1/1/10). Levels 3/4 are user-requested in 10-step
     * batches up to caps 20 and 30.
     */
    const LEVEL_RULES = [
        0 => ['cap' => 1,  'default' => 1,  'options' => [],           'auto' => true],
        1 => ['cap' => 1,  'default' => 1,  'options' => [],           'auto' => true],
        2 => ['cap' => 10, 'default' => 10, 'options' => [],           'auto' => true],
        3 => ['cap' => 20, 'default' => 0,  'options' => [10, 20],     'auto' => false],
        4 => ['cap' => 30, 'default' => 0,  'options' => [10, 20, 30], 'auto' => false],
    ];

    protected $fillable = [
        'user_id',
        'user_promoter_id',
        'level',
        'quantity',
        'delivery_type',
        'delivery_address',
        'pickup_date',
        'contact_number',
        'status',
        'requested_at',
        'sent_at',
        'sent_by',
        'dispatch_method',
        'collected_date',
        'courier_name',
        'courier_number',
        'delivered_at',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function userPromoter()
    {
        return $this->belongsTo(UserPromoter::class);
    }

    /**
     * Rules for a level, or null if the level isn't box-eligible.
     */
    public static function rulesForLevel($level): ?array
    {
        return self::LEVEL_RULES[(int) $level] ?? null;
    }

    /**
     * Total boxes already requested (any status) by a user at a level. All
     * non-deleted batches count toward the cap — there's no cancel/reject.
     */
    public static function receivedQuantity(int $userId, int $level): int
    {
        return (int) self::where('user_id', $userId)
            ->where('level', $level)
            ->where('is_deleted', 0)
            ->sum('quantity');
    }

    /**
     * Boxes still available within the level cap for this user.
     */
    public static function remainingForLevel(int $userId, int $level): int
    {
        $rules = self::rulesForLevel($level);
        if (!$rules) {
            return 0;
        }
        return max(0, (int) $rules['cap'] - self::receivedQuantity($userId, $level));
    }

    /**
     * Batch sizes the user can still pick, given the remaining cap. Empty for
     * auto levels (0/1/2) and once the cap is reached.
     */
    public static function selectableOptions(int $userId, int $level): array
    {
        $rules = self::rulesForLevel($level);
        if (!$rules || $rules['auto']) {
            return [];
        }
        $remaining = self::remainingForLevel($userId, $level);
        return array_values(array_filter(
            $rules['options'],
            fn ($option) => $option <= $remaining
        ));
    }

    /** "Direct" / "Courier", or null when the batch hasn't been sent yet. */
    public function dispatchLabel(): ?string
    {
        return [
            self::DISPATCH_DIRECT  => 'Direct',
            self::DISPATCH_COURIER => 'Courier',
        ][(int) $this->dispatch_method] ?? null;
    }

    /**
     * One-line summary of how it was dispatched, for a table cell or the
     * user's card. Null when there is nothing recorded.
     */
    public function dispatchSummary(): ?string
    {
        if ((int) $this->dispatch_method === self::DISPATCH_DIRECT) {
            return $this->collected_date
                ? 'Collected on ' . date('d-m-Y', strtotime((string) $this->collected_date))
                : 'Collected directly';
        }

        if ((int) $this->dispatch_method === self::DISPATCH_COURIER) {
            $parts = array_filter([
                $this->courier_name,
                $this->courier_number ? '#' . $this->courier_number : null,
            ]);

            return $parts ? implode(' ', $parts) : 'Sent by courier';
        }

        return null;
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_REQUESTED => 'Requested',
            self::STATUS_SENT => 'Sent',
            self::STATUS_DELIVERED => 'Delivered',
        ][(int) $this->status] ?? 'Requested';
    }
}
