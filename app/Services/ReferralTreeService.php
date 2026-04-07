<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class ReferralTreeService
{
    public function getDirectReferrals(int $userId): Collection
    {
        return User::query()
            ->where('referred_by', $userId)
            ->where('is_deleted', 0)
            ->get();
    }

    public function getAncestors(int $userId, ?int $maxDepth = null): Collection
    {
        $ancestors = collect();
        $currentUser = User::find($userId);
        $depth = 1;

        while ($currentUser && $currentUser->referred_by) {
            if ($maxDepth !== null && $depth > $maxDepth) {
                break;
            }

            $ancestor = User::query()
                ->where('id', $currentUser->referred_by)
                ->where('is_deleted', 0)
                ->first();

            if (!$ancestor) {
                break;
            }

            $ancestors->push([
                'depth' => $depth,
                'user' => $ancestor,
            ]);

            $currentUser = $ancestor;
            $depth++;
        }

        return $ancestors;
    }

    public function getTeamCountsByDepth(int $userId, ?int $maxDepth = null, bool $activeOnly = false): Collection
    {
        $counts = collect();
        $currentLevelIds = [$userId];
        $depth = 1;

        while (!empty($currentLevelIds)) {
            if ($maxDepth !== null && $depth > $maxDepth) {
                break;
            }

            $query = User::query()
                ->whereIn('referred_by', $currentLevelIds)
                ->where('is_deleted', 0);

            if ($activeOnly) {
                $query->where('is_active', 1);
            }

            $children = $query->get(['id']);
            $childIds = $children->pluck('id')->all();

            if (empty($childIds)) {
                break;
            }

            $counts->push([
                'depth' => $depth,
                'count' => count($childIds),
            ]);

            $currentLevelIds = $childIds;
            $depth++;
        }

        return $counts;
    }

    public function getMaxConfiguredDepthForUser(int $userId): int
    {
        return max(1, (int) $this->getTeamCountsByDepth($userId)->max('depth'));
    }
}
