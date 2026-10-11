<?php

namespace Modules\Game\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class GameServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Game';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'game';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
