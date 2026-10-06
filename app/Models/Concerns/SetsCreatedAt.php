<?php

namespace App\Models\Concerns;

/**
 * Fills in CreatedAt with the app's time (Malaysia time, config('app.timezone')) when a record is added, rather than
 * leaving it to the column's database default. A local SQL Server's GETDATE() keeps Malaysia time, but Azure SQL's is
 * always UTC, which would put every new user, role, menu link and route 8 hours early.
 */
trait SetsCreatedAt
{
    protected static function bootSetsCreatedAt(): void
    {
        static::creating(function (self $model) {
            $model->CreatedAt ??= now();
        });
    }
}
