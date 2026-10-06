<?php

namespace Farysasyraf\SavedRoutes;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use ReflectionClass;
use ReflectionMethod;

/**
 * Finds the controllers and functions a saved route can point to: only classes in the configured controller
 * folders, built on the base class, and only public functions written in those folders rather than inherited
 * from Laravel. Saved routes are added from a page, so this is what keeps that page from pointing an address
 * at anything else.
 */
class ControllerLocator
{
    /**
     * @var ?array<string, array{namespace: string, path: string}>
     */
    private ?array $folders = null;

    /**
     * @param  array{folders?: array<string, array{namespace: string, path: string}>, base_class?: ?string, modules?: bool}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * Where a route's controller can live, by the prefix that names it: '' for the app's own controllers, and a
     * module's name for that module's Http/Controllers. Only enabled modules count.
     *
     * @return array<string, array{namespace: string, path: string}>
     */
    public function folders(): array
    {
        return $this->folders ??= $this->findFolders();
    }

    /**
     * @return array<string, array{namespace: string, path: string}>
     */
    private function findFolders(): array
    {
        $folders = [];

        foreach ($this->config['folders'] ?? [] as $prefix => $folder) {
            $folders[(string) $prefix] = ['namespace' => trim($folder['namespace'], '\\').'\\', 'path' => $folder['path']];
        }

        if (($this->config['modules'] ?? false) && class_exists(Module::class)) {
            $inModule = config('modules.paths.generator.controller.path', 'Http/Controllers');

            foreach (Module::allEnabled() as $module) {
                $folders[$module->getName()] ??= [
                    'namespace' => config('modules.namespace', 'Modules').'\\'.$module->getName().'\\'.str_replace('/', '\\', $inModule).'\\',
                    'path' => $module->getPath().DIRECTORY_SEPARATOR.$inModule,
                ];
            }
        }

        return $folders;
    }

    /**
     * Find the controllers a typed name could mean: ReportController or Admin\ReportController in the app's
     * folder; Setup\RoleController in a module or prefixed folder; or just RoleController, in whichever folder has
     * it. A full class name works too.
     *
     * @return list<ReflectionClass<object>>
     */
    public function find(string $name): array
    {
        $name = Str::of($name)->trim()->replace('/', '\\')->ltrim('\\')->value();
        $folders = $this->folders();
        $prefix = Str::before($name, '\\');

        $candidates = match (true) {
            // A full class name, like Modules\Setup\Http\Controllers\RoleController.
            collect($folders)->contains(fn (array $folder) => str_starts_with($name, $folder['namespace'])) => collect($folders)
                ->filter(fn (array $folder) => str_starts_with($name, $folder['namespace']))
                ->map(fn (array $folder) => [$folder['namespace'], Str::after($name, $folder['namespace'])]),
            // A folder's prefix first, like Setup\RoleController.
            $prefix !== '' && $prefix !== $name && isset($folders[$prefix]) => collect([[$folders[$prefix]['namespace'], Str::after($name, '\\')]]),
            default => collect($folders)->map(fn (array $folder) => [$folder['namespace'], $name]),
        };

        return $candidates
            // Letters, numbers and _ only, so the name can't point outside a controllers folder.
            ->filter(fn (array $candidate) => preg_match('/^[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)*$/', $candidate[1]) && class_exists($candidate[0].$candidate[1]))
            ->map(fn (array $candidate) => new ReflectionClass($candidate[0].$candidate[1]))
            ->filter(fn (ReflectionClass $controller) => $this->isController($controller))
            ->unique(fn (ReflectionClass $controller) => strtolower($controller->getName()))
            ->values()
            ->all();
    }

    /**
     * The one controller a name means, or null when there's none, or more than one.
     *
     * @return ?ReflectionClass<object>
     */
    public function findOne(string $name): ?ReflectionClass
    {
        $found = $this->find($name);

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Whether a class can handle a saved route: one that can be made, built on the base class when there is one.
     * A base class that's set but doesn't exist lets nothing through.
     */
    private function isController(ReflectionClass $controller): bool
    {
        $base = $this->config['base_class'] ?? null;

        if (! $controller->isInstantiable()) {
            return false;
        }

        return $base === null || (class_exists($base) && $controller->isSubclassOf($base));
    }

    /**
     * How the admin page names a controller: its class within its folder, like ReportController, after the folder's
     * prefix when it has one, like Setup\RoleController. That's also how a saved route stores it.
     */
    public function nameOf(ReflectionClass $controller): string
    {
        foreach ($this->folders() as $prefix => $folder) {
            if (str_starts_with($controller->getName(), $folder['namespace'])) {
                $inFolder = Str::after($controller->getName(), $folder['namespace']);

                return $prefix === '' ? $inFolder : $prefix.'\\'.$inFolder;
            }
        }

        return $controller->getName();
    }

    /**
     * The full class name a stored controller name stands for, like Modules\Setup\Http\Controllers\RoleController.
     */
    public function classFor(string $name): string
    {
        $folders = $this->folders();
        $prefix = Str::before($name, '\\');

        if ($prefix !== '' && $prefix !== $name && isset($folders[$prefix])) {
            return $folders[$prefix]['namespace'].Str::after($name, '\\');
        }

        return isset($folders['']) ? $folders['']['namespace'].$name : $name;
    }

    /**
     * Find a function a route can call: public, not static, not a PHP magic method, and written in one of the
     * controller folders rather than inherited from Laravel.
     */
    public function findAction(ReflectionClass $controller, string $name): ?ReflectionMethod
    {
        $name = trim($name);

        if (! preg_match('/^[A-Za-z_]\w*$/', $name) || str_starts_with($name, '__') || ! $controller->hasMethod($name)) {
            return null;
        }

        $action = $controller->getMethod($name);
        $writtenBy = $action->getDeclaringClass()->getName();

        return $action->isPublic() && ! $action->isStatic() && ! $action->isAbstract()
            && collect($this->folders())->contains(fn (array $folder) => str_starts_with($writtenBy, $folder['namespace']))
            ? $action
            : null;
    }

    /**
     * Every controller a route can point to, each with the functions it can call, for suggestions on the form.
     *
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->folders() as $folder) {
            if (! is_dir($folder['path'])) {
                continue;
            }

            foreach (File::allFiles($folder['path']) as $file) {
                $controller = $file->getExtension() === 'php'
                    ? $this->findOne($folder['namespace'].str_replace('/', '\\', Str::chopEnd($file->getRelativePathname(), '.php')))
                    : null;

                if ($controller !== null) {
                    $options[$this->nameOf($controller)] = collect($controller->getMethods())
                        ->filter(fn (ReflectionMethod $action) => $this->findAction($controller, $action->getName()) !== null)
                        ->map(fn (ReflectionMethod $action) => $action->getName())
                        ->values()
                        ->all();
                }
            }
        }

        ksort($options);

        return $options;
    }
}
