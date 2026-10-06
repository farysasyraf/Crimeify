<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers;

use Farysasyraf\SavedRoutes\Tests\Fixtures\User;

class UserController extends Controller
{
    public function edit(User $user): string
    {
        return "Editing {$user->name}";
    }
}
