<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// Mirrors dbo.UserPhotos: a user's profile photo, as the bytes of a JPG or PNG.
#[Table(name: 'UserPhotos', key: 'UserId', incrementing: false, timestamps: false)]
class UserPhoto extends Model
{
    /**
     * The types a photo is stored as.
     */
    public const Types = ['image/jpeg', 'image/png'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['UpdatedAt' => 'datetime'];
    }

    /**
     * Save a user's photo, replacing the one they had.
     */
    public static function saveFor(User $user, string $bytes, string $contentType): void
    {
        $connection = (new static)->getConnection();

        $connection->transaction(function () use ($connection, $user, $bytes, $contentType) {
            static::query()->whereKey($user->Id)->delete();

            // SQL Server's PHP driver sends every string as text, which SQL Server won't turn into varbinary, so the
            // bytes go as hex and SQL Server turns them back into bytes.
            $sqlServer = $connection->getDriverName() === 'sqlsrv';

            $connection->insert(
                'INSERT INTO UserPhotos (UserId, Photo, ContentType, UpdatedAt) VALUES (?, '.($sqlServer ? 'CONVERT(varbinary(max), ?, 2)' : '?').', ?, ?)',
                [$user->Id, $sqlServer ? bin2hex($bytes) : $bytes, $contentType, now()],
            );
        });
    }
}
