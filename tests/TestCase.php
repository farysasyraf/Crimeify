<?php

namespace Tests;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The app adds the routes saved on the Routes page as it starts, but a test's database is only made after that,
     * with the pages the migrations save there (users, roles, menu-items, dashboard, crime-data…). So add them now,
     * as the app would.
     */
    protected function setUp(): void
    {
        parent::setUp();

        SavedRoutes::register();

        // Without the crime by state file (StateCrime), so the crime import loads only a test's own figures. The
        // tests of it give it a file.
        config(['map.by_state.file' => null]);
    }

    /**
     * Log in as a user that can log in, creating it in dbo.Users. They have the ADMIN role unless $admin is false,
     * as the add, edit and delete routes start ADMIN-only on the Routes page.
     */
    protected function signIn(bool $admin = true): User
    {
        $user = User::create(['Name' => 'Signed In', 'Email' => 'me@example.com', 'Password' => 'correct-horse']);

        if ($admin) {
            $user->roles()->attach(Role::firstOrCreate(['Name' => 'ADMIN'])->Id);
        }

        $this->actingAs($user);

        return $user;
    }

    /**
     * How the sidebar draws a link at any level, from its current-page state through its icon to its label,
     * like aria-current="page"><span …>content_paste</span><span class="side-text">Users</span></a>.
     */
    protected function sideLink(string $label, string $current = 'false', string $icon = MenuItem::DefaultIcon): string
    {
        return 'aria-current="'.$current.'"><span class="material-icon side-icon" aria-hidden="true">'.$icon.'</span>'
            .'<span class="side-text">'.$label.'</span></a>';
    }
}
