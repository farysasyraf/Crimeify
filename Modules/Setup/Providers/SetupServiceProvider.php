<?php

namespace Modules\Setup\Providers;

use App\Models\User;
use App\Support\Palette;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Setup\Console\ExportMenu;
use Modules\Setup\Console\ImportMenu;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SetupServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Setup';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'setup';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ExportMenu::class,
        ImportMenu::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // The command palette's users, by name, username or email, each to its Edit user page, for whoever can open it.
        $this->app->make(Palette::class)->add('Users', 20, function (string $term, User $user) {
            if ($term === '' || ! Palette::canOpen($user, 'users/edit')) {
                return [];
            }

            return User::query()->search($term)->orderBy('Name')->limit(20)->get()
                ->map(fn (User $found) => [
                    'label' => $found->Name,
                    'about' => "{$found->Username} · {$found->Email}",
                    'also' => "{$found->Username} {$found->Email}",
                    'url' => route('users/edit', $found),
                    'icon' => 'person',
                ]);
        });
    }

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
