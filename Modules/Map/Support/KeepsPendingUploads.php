<?php

namespace Modules\Map\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// An uploaded Excel file's changes, waiting on the server to be applied or cancelled after they've been shown: on the
// Crime data and Police stations pages. Checked in steps (upload-progress.js), an upload first keeps the file's rows,
// then, compared, its changes in their place. They're kept on the "local" disk, in the folder the controller names
// (self::Uploads), for the user who uploaded the file only, and forgotten after a day if never applied or cancelled.
trait KeepsPendingUploads
{
    /**
     * Keep an upload for the user uploading it, and give the key to go on with it: to compare, review, apply or cancel
     * it. Given an upload's key, what it keeps is replaced, like its rows by their changes.
     *
     * @param  array<string, mixed>  $pending  what to keep, like the file's name and its changes
     */
    private function keepUpload(Request $request, array $pending, ?string $upload = null): string
    {
        $this->forgetOldUploads();
        $upload ??= (string) Str::uuid();
        Storage::disk('local')->put(self::Uploads."/{$upload}.json", json_encode(['user' => $request->user()->Id] + $pending, JSON_THROW_ON_ERROR));

        return $upload;
    }

    /**
     * An upload as it waits. Only the user who uploaded it can go on with it, and only to a step it's ready for: one
     * whose rows haven't been compared can't be reviewed or applied, for one.
     *
     * @param  string|null  $needs  what it must have kept by now, like "changes"
     * @return array<string, mixed>
     */
    private function pendingUpload(Request $request, string $upload, ?string $needs = null): array
    {
        $path = self::Uploads."/{$upload}.json";
        abort_unless(Str::isUuid($upload) && Storage::disk('local')->exists($path), 404, 'That upload has already been applied or cancelled.');

        $pending = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        abort_unless($pending['user'] === $request->user()->Id, 404);
        abort_unless($needs === null || array_key_exists($needs, $pending), 404);

        return $pending;
    }

    private function forgetUpload(string $upload): void
    {
        Storage::disk('local')->delete(self::Uploads."/{$upload}.json");
    }

    /**
     * Uploads checked but never applied or cancelled, from over a day ago, are forgotten.
     */
    private function forgetOldUploads(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(self::Uploads) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
