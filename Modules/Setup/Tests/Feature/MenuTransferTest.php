<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\Setup\Support\MenuTransfer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * menu:export and menu:import: copying the menu and routes made on the Manage menu and Manage routes pages from one
 * database to another, like from your own computer's to the cloud host's.
 */
class MenuTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->file = Storage::disk('local')->path('menu.json');
    }

    /**
     * A menu like the one made by hand: the Map module's group and page, open to a role of its own, with its routes.
     */
    private function buildMapModule(): void
    {
        // In place of the Map page the migrations add, open to everyone.
        AppRoute::where('Path', 'like', 'map%')->delete();
        MenuItem::where('Url', '/map')->delete();
        MenuItem::where('Label', 'Map Module')->delete();

        $editor = Role::create(['Name' => 'EDITOR', 'Description' => 'Edits the map.']);

        $group = MenuItem::create(['Label' => 'Map Module', 'SortOrder' => 7, 'Icon' => 'map', 'VisibleToEveryone' => true]);
        $map = MenuItem::create(['Label' => 'Map', 'Url' => '/map', 'SortOrder' => 1, 'Icon' => 'place', 'ParentId' => $group->Id, 'VisibleToEveryone' => false]);
        $map->roles()->attach($editor->Id);

        $route = AppRoute::create(['MenuItemId' => $map->Id, 'Path' => 'map', 'Controller' => 'Map\MapController', 'Action' => 'index', 'HttpMethods' => 'GET', 'OpenToEveryone' => false]);
        $route->roles()->attach($editor->Id);
        AppRoute::create(['MenuItemId' => null, 'Path' => 'map/crime', 'Controller' => 'Map\MapController', 'Action' => 'crime', 'HttpMethods' => 'GET', 'OpenToEveryone' => true]);
    }

    /**
     * An export with each link named by its labels from the top, not its Id, which differs between databases.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function byLabel(array $data): array
    {
        $labels = collect($data['menu'])->pluck('label', 'key');
        $parents = collect($data['menu'])->pluck('parent', 'key');
        $trail = function ($key) use ($labels, $parents) {
            $names = [];
            for ($at = $key; $at !== null; $at = $parents[$at]) {
                array_unshift($names, $labels[$at]);
            }

            return implode(' > ', $names);
        };

        return [
            'menu' => collect($data['menu'])->map(fn ($item) => ['at' => $trail($item['key'])] + array_diff_key($item, ['key' => 0, 'parent' => 0]))->sortBy('at')->values()->all(),
            'routes' => collect($data['routes'])->map(fn ($route) => ['menu' => $route['menu'] === null ? null : $trail($route['menu'])] + $route)->sortBy(fn ($route) => $route['method'].' '.$route['path'])->values()->all(),
        ];
    }

    public function test_the_menu_and_routes_go_from_one_database_to_another_with_their_roles(): void
    {
        $this->buildMapModule();
        $this->artisan('menu:export', ['file' => $this->file])
            ->expectsOutputToContain('Saved '.MenuItem::count().' menu links, '.AppRoute::count().' routes and 2 roles')
            ->assertSuccessful();
        $exported = json_decode(File::get($this->file), true);

        // The other database: only what the migrations add, as a new one has, and no EDITOR role.
        MenuItem::whereIn('Label', ['Map', 'Map Module'])->orderByDesc('Id')->get()->each->delete();
        AppRoute::where('Path', 'like', 'map%')->delete();
        Role::where('Name', 'EDITOR')->delete();
        $users = User::count();

        $this->artisan('menu:import', ['file' => $this->file, '--force' => true])
            ->expectsOutputToContain('Done: '.count($exported['menu']).' menu links and '.count($exported['routes']).' routes.')
            ->expectsOutputToContain('Roles added, as the file has them: EDITOR.')
            ->expectsOutputToContain('What was here before is saved in')
            ->assertSuccessful();

        // The same menu and routes, each under the same link, for the same roles; users as they were.
        $this->assertEquals($this->byLabel($exported), $this->byLabel(MenuTransfer::export()));
        $this->assertSame('Edits the map.', Role::firstWhere('Name', 'EDITOR')->Description);
        $this->assertSame($users, User::count());
        $this->assertCount(1, Storage::disk('local')->files('menu-backups'));

        // And the app uses them straight away: someone with EDITOR sees the Map link and can open the page.
        AppRoute::registerBehindLogin();
        $editor = $this->signIn(admin: false);
        $editor->roles()->attach(Role::firstWhere('Name', 'EDITOR')->Id);
        $this->get('/map')->assertOk()->assertSee($this->sideLink('Map', 'page', 'place'), false);
    }

    public function test_a_backup_put_back_with_menu_import_undoes_an_import(): void
    {
        $this->buildMapModule();
        $this->artisan('menu:export', ['file' => $this->file])->assertSuccessful();
        AppRoute::where('Path', 'like', 'map%')->delete();
        MenuItem::whereIn('Label', ['Map', 'Map Module'])->orderByDesc('Id')->get()->each->delete();
        $withoutMap = $this->byLabel(MenuTransfer::export());

        $this->artisan('menu:import', ['file' => $this->file, '--force' => true])->assertSuccessful();
        $this->assertNotEquals($withoutMap, $this->byLabel(MenuTransfer::export()));

        $backup = Storage::disk('local')->path(Storage::disk('local')->files('menu-backups')[0]);
        $this->artisan('menu:import', ['file' => $backup, '--force' => true])->assertSuccessful();
        $this->assertEquals($withoutMap, $this->byLabel(MenuTransfer::export()));
    }

    public function test_it_asks_before_replacing_and_changes_nothing_when_told_no(): void
    {
        $this->buildMapModule();
        $this->artisan('menu:export', ['file' => $this->file])->assertSuccessful();
        $before = MenuTransfer::export();

        $this->artisan('menu:import', ['file' => $this->file])
            ->expectsOutputToContain('The file: '.MenuItem::count().' menu links and '.AppRoute::count().' routes from')
            ->expectsConfirmation("Replace this database's menu links and routes with the file's?", 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(array_diff_key($before, ['exported_at' => 0]), array_diff_key(MenuTransfer::export(), ['exported_at' => 0]));
        $this->assertFalse(Storage::disk('local')->exists('menu-backups'));
    }

    /**
     * @return array<string, array{0: callable(array): mixed, 1: string}>
     */
    public static function brokenFiles(): array
    {
        return [
            'not from menu:export' => [fn ($data) => ['menu' => []], "This isn't a file from \"php artisan menu:export\"."],
            'a link under one not in the file' => [function ($data) {
                $data['menu'][0]['parent'] = 999;

                return $data;
            }, "sits under a link that isn't in the file."],
            'a link under itself' => [function ($data) {
                $data['menu'][0]['parent'] = $data['menu'][0]['key'];

                return $data;
            }, 'or under itself.'],
            'a route under a link not in the file' => [function ($data) {
                $data['routes'][0]['menu'] = 999;

                return $data;
            }, "is under a menu link that isn't in the file."],
            'a role no one has' => [function ($data) {
                $data['routes'][0]['roles'] = ['NOBODY'];

                return $data;
            }, "is for the role NOBODY, which isn't in the file or this database."],
            'a method routes can\'t have' => [function ($data) {
                $data['routes'][0]['method'] = 'PATCH';

                return $data;
            }, 'is missing its path, controller, function, method, who can open it or its roles.'],
        ];
    }

    #[DataProvider('brokenFiles')]
    public function test_a_file_that_doesnt_hang_together_changes_nothing(callable $break, string $problem): void
    {
        $this->buildMapModule();
        $before = MenuTransfer::export();
        File::put($this->file, json_encode($break($before)));

        $this->artisan('menu:import', ['file' => $this->file, '--force' => true])
            ->expectsOutputToContain($problem.' Nothing was changed.')
            ->assertFailed();

        $this->assertSame(array_diff_key($before, ['exported_at' => 0]), array_diff_key(MenuTransfer::export(), ['exported_at' => 0]));
    }

    public function test_it_says_so_when_the_file_is_missing_or_not_json(): void
    {
        $this->artisan('menu:import', ['file' => $this->file, '--force' => true])
            ->expectsOutput("There's no file at {$this->file}.")
            ->assertFailed();

        File::put($this->file, 'not json');
        $this->artisan('menu:import', ['file' => $this->file, '--force' => true])
            ->expectsOutput("{$this->file} isn't a JSON file.")
            ->assertFailed();
    }
}
