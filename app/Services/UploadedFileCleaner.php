<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Removes uploaded files from disk once nothing points at them any more.
 *
 * Records are soft-deleted (is_deleted = 1), so without this the file behind
 * every deleted or re-uploaded record stays on disk forever. A handful of
 * replaced 500 MB videos is enough to fill a server.
 *
 * The rule is deliberately conservative: a file is removed only when NO LIVE
 * record anywhere references it. That matters because the same stored name
 * could be referenced twice — a personal document mirrors its first file onto
 * the parent row, for instance — and deleting a file another record still
 * needs would be unrecoverable.
 *
 * Call it AFTER the row has been marked deleted/updated and committed, so the
 * row being discarded no longer counts as a live reference.
 */
class UploadedFileCleaner
{
    /**
     * Every table/column pair that can hold a stored upload name.
     * Add to this whenever a new feature starts referencing uploads —
     * a missing entry here means a file can be deleted out from under it.
     */
    private const REFERENCES = [
        ['daily_videos', 'video_path'],
        ['promotion_videos', 'video_path'],
        ['training_videos', 'video_path'],
        ['company_documents', 'file_path'],
        ['company_personal_documents', 'file_path'],
        ['company_personal_document_files', 'file_path'],
    ];

    /**
     * Directories a stored file may live in. Personal documents are moved to
     * private storage; everything else stays in the public upload dir. The
     * "original" dir holds pre-compression backups of videos.
     */
    private function candidatePaths(string $name): array
    {
        return [
            storage_path('app/personal-documents/' . $name),
            storage_path('app/public/uploads/final/' . $name),
            storage_path('app/public/uploads/original/' . $name),
        ];
    }

    /** Pin to a bare filename so a stored value can never escape the dirs. */
    private function safeName(?string $storedName): string
    {
        $name = basename(str_replace('\\', '/', (string) $storedName));

        return in_array($name, ['', '.', '..'], true) ? '' : $name;
    }

    /** True when some live (non-deleted) row still references this file. */
    public function isReferenced(string $name): bool
    {
        foreach (self::REFERENCES as [$table, $column]) {
            try {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                    continue;
                }

                $query = DB::table($table)->where($column, $name);
                if (Schema::hasColumn($table, 'is_deleted')) {
                    $query->where('is_deleted', 0);
                }

                if ($query->exists()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // A broken reference check must never delete a file by
                // accident — treat "unknown" as "still referenced".
                Log::warning('UploadedFileCleaner reference check failed', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Delete the file behind $storedName if nothing live references it.
     * Returns true when something was actually removed. Never throws.
     */
    public function forget(?string $storedName): bool
    {
        $name = $this->safeName($storedName);
        if ($name === '') {
            return false;
        }

        try {
            if ($this->isReferenced($name)) {
                return false;
            }

            $removed = false;
            foreach ($this->candidatePaths($name) as $path) {
                if (is_file($path) && @unlink($path)) {
                    $removed = true;
                }
            }

            return $removed;
        } catch (\Throwable $e) {
            // Storage cleanup must never break the user's action.
            Log::warning('UploadedFileCleaner failed', [
                'file'  => $name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Convenience for a set of names (a personal-document file set). */
    public function forgetMany(iterable $storedNames): int
    {
        $count = 0;
        foreach ($storedNames as $name) {
            if ($this->forget($name)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete the old file after a record's upload was replaced.
     * No-op when the value didn't actually change.
     */
    public function replaced(?string $oldName, ?string $newName): bool
    {
        $old = $this->safeName($oldName);
        if ($old === '' || $old === $this->safeName($newName)) {
            return false;
        }

        return $this->forget($old);
    }
}
