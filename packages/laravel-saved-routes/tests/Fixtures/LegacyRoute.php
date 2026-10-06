<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures;

use Farysasyraf\SavedRoutes\Concerns\ActsAsSavedRoute;
use Farysasyraf\SavedRoutes\Contracts\RouteRecord;
use Illuminate\Database\Eloquent\Model;

// An app's own table of routes, with its own column names, as an app that had saved routes before the package does.
class LegacyRoute extends Model implements RouteRecord
{
    use ActsAsSavedRoute;

    protected $table = 'LegacyRoutes';

    protected $primaryKey = 'Id';

    public $timestamps = false;

    protected $guarded = [];

    public static function savedRoutes(): iterable
    {
        return static::orderBy('Id')->get();
    }

    public function savedRouteKey(): int|string
    {
        return $this->Id;
    }

    public function savedRoutePath(): string
    {
        return $this->Address;
    }

    public function savedRouteParameters(): ?string
    {
        return $this->Params;
    }

    public function savedRouteController(): string
    {
        return $this->Controller;
    }

    public function savedRouteAction(): string
    {
        return $this->Function;
    }

    public function savedRouteMethod(): string
    {
        return $this->Verb;
    }

    public function savedRouteRoles(): ?array
    {
        return $this->AdminOnly ? [Role::firstWhere('name', 'admin')?->id ?? 0 => 'admin'] : null;
    }
}
