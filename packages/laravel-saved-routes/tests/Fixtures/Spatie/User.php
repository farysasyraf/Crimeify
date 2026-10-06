<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Spatie;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $guard_name = 'web';
}
