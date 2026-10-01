<?php

namespace Modules\Map\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// A police station changed, added or deleted on the Police stations page, by hand or by uploading an Excel file, kept
// in dbo.PoliceStationEdits: who did it and when, and the station's details before and after, which the page lists
// under Update Logs by User.
#[Table(name: 'PoliceStationEdits', key: 'Id', timestamps: false)]
#[Fillable(['UserId', 'UserName', 'Action', 'Source', 'StationId', 'OldDetails', 'NewDetails', 'CreatedAt'])]
class PoliceStationEdit extends Model
{
    /**
     * Rows per insert, so each stays under SQL Server's limit of 2100 values per query.
     */
    private const Chunk = 200;

    /**
     * Record changes one user made at once, on the page ("page") or from an uploaded file ("upload"). Each is
     * "changed" (with old and new details), "added" (with new) or "deleted" (with old), with the station's id.
     *
     * @param  list<array{action: string, id: int, old: ?array<string, ?string>, new: ?array<string, ?string>}>  $changes
     */
    public static function record(User $user, string $source, array $changes): void
    {
        $now = now();
        // As JSON, with every letter and slash as typed rather than escaped, so the table reads well in SQL Server too.
        $json = fn (?array $details) => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach (array_chunk($changes, self::Chunk) as $chunk) {
            static::insert(array_map(fn (array $change) => [
                'UserId' => $user->Id, 'UserName' => $user->Name, 'Action' => $change['action'], 'Source' => $source,
                'StationId' => $change['id'], 'OldDetails' => $json($change['old']), 'NewDetails' => $json($change['new']),
                'CreatedAt' => $now,
            ], $chunk));
        }
    }

    /**
     * The station's details as the edit left them, or as they were before it was deleted.
     *
     * @return array<string, ?string>
     */
    public function station(): array
    {
        return $this->NewDetails ?? $this->OldDetails;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // SQL Server's driver returns numbers as strings.
        return [
            'UserId' => 'integer',
            'StationId' => 'integer',
            'OldDetails' => 'array',
            'NewDetails' => 'array',
            'CreatedAt' => 'datetime',
        ];
    }
}
