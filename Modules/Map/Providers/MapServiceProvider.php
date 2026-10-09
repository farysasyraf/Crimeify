<?php

namespace Modules\Map\Providers;

use App\Models\User;
use App\Support\Palette;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Map\Console\ImportCrimeData;
use Modules\Map\Console\ImportPoliceStations;
use Modules\Map\Console\ImportPopulation;
use Modules\Map\Console\ImportStateCrime;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;
use Nwidart\Modules\Support\ModuleServiceProvider;

class MapServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Map';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'map';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ImportCrimeData::class,
        ImportPoliceStations::class,
        ImportPopulation::class,
        ImportStateCrime::class,
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

        // The command palette's police districts and stations, each to its place on the Map page, which opens the
        // district's pin, or shows the station in "Find a police station", for whoever can open the map.
        $palette = $this->app->make(Palette::class);

        $palette->add('Police districts', 30, function (string $term, User $user) {
            if ($term === '' || ! Palette::canOpen($user, 'map')) {
                return [];
            }

            return PoliceDistrict::query()->whereRaw("Name LIKE ? ESCAPE '\\'", [Palette::like($term)])->orderBy('Name')->limit(20)->get()
                ->map(fn (PoliceDistrict $district) => [
                    'label' => $district->Name,
                    'about' => "Police district in {$district->regionName()}",
                    'url' => route('map', ['district' => implode('/', $district->path())]),
                    'icon' => 'location_on',
                ]);
        });

        $palette->add('Police stations', 40, function (string $term, User $user) {
            if ($term === '' || ! Palette::canOpen($user, 'map')) {
                return [];
            }

            return PoliceStation::query()->whereRaw("Name LIKE ? ESCAPE '\\'", [Palette::like($term)])->orderBy('Name')->limit(20)->get()
                ->map(fn (PoliceStation $station) => [
                    'label' => $station->Name,
                    'about' => "Police station in {$station->District}, {$station->regionName()}",
                    'url' => route('map', ['station' => $station->Id]),
                    'icon' => 'local_police',
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
