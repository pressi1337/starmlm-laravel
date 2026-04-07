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

    public function getRootAncestorId(int $userId): int
    {
        $currentUser = User::find($userId);
        $rootId = $userId;

        while ($currentUser && $currentUser->referred_by) {
            $ancestor = User::query()
                ->where('id', $currentUser->referred_by)
                ->where('is_deleted', 0)
                ->first();

            if (!$ancestor) {
                break;
            }

            $rootId = (int) $ancestor->id;
            $currentUser = $ancestor;
        }

        return $rootId;
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

    public function getTeamTree(int $userId, ?int $maxDepth = null, bool $activeOnly = false): Collection
    {
        $children = $this->getChildrenForTree($userId, $activeOnly);

        return $children->map(function (User $user) use ($maxDepth, $activeOnly) {
            return $this->formatTreeNode($user, 1, $maxDepth, $activeOnly);
        })->values();
    }

    protected function getChildrenForTree(int $userId, bool $activeOnly = false): Collection
    {
        $query = User::query()
            ->where('referred_by', $userId)
            ->where('is_deleted', 0)
            ->orderBy('created_at');

        if ($activeOnly) {
            $query->where('is_active', 1);
        }

        return $query->get();
    }

    protected function formatTreeNode(User $user, int $depth, ?int $maxDepth, bool $activeOnly): array
    {
        $children = collect();

        if ($maxDepth === null || $depth < $maxDepth) {
            $children = $this->getChildrenForTree((int) $user->id, $activeOnly)
                ->map(function (User $child) use ($depth, $maxDepth, $activeOnly) {
                    return $this->formatTreeNode($child, $depth + 1, $maxDepth, $activeOnly);
                })
                ->values();
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'mobile' => $user->mobile,
            'current_promoter_level' => $user->current_promoter_level,
            'promoter_label' => User::promoterLevelLabel($user->current_promoter_level),
            'promoter_status' => $user->promoter_status,
            'training_status' => $user->training_status,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
            'depth' => $depth,
            'direct_children_count' => $children->count(),
            'children' => $children,
        ];
    }
}
