<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private MenuItem $settings;

    private MenuItem $roles;

    private MenuItem $reports;

    /**
     * Settings (a heading) > Roles (a link) > Role reports.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->signIn();

        $this->settings = MenuItem::create(['Label' => 'Settings', 'Url' => null, 'SortOrder' => 10, 'VisibleToEveryone' => true, 'Icon' => 'settings']);
        $this->roles = MenuItem::where('Url', '/roles')->firstOrFail();
        $this->roles->update(['ParentId' => $this->settings->Id]);
        $this->reports = MenuItem::create([
            'Label' => 'Role reports', 'Url' => '/roles/reports', 'SortOrder' => 1,
            'VisibleToEveryone' => true, 'ParentId' => $this->roles->Id, 'Icon' => 'assessment',
        ]);
    }

    public function test_sidebar_is_laid_out_like_amv(): void
    {
        $this->assertFileExists(public_path('images/logo.png'));
        $this->assertFileExists(public_path('js/sidebar.js'));
        $this->assertFileExists(public_path('fonts/material-icons-round.woff2'));

        $this->get('/users')->assertSeeInOrder([
            '<aside class="sidebar" id="sidebar">',
            '<a class="side-brand"',
            asset('images/logo.png'),
            '<button type="button" class="side-close" aria-label="Close menu">',
            '<hr class="side-divider" />',
            '<ul class="side-list side-level-1 side-profile">',
            '<hr class="side-divider" />',
            '<nav class="side-nav"',
            '<h2 class="side-section">Modules</h2>',
            asset('js/sidebar.js'),
            '</aside>',
        ], false);
    }

    public function test_there_are_buttons_to_open_the_menu_on_small_screens_and_narrow_it_on_wide_ones(): void
    {
        $this->get('/users')
            ->assertSee('class="btn btn-sm btn-ghost menu-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"', false)
            ->assertSee('class="btn btn-sm btn-ghost menu-narrow-toggle" aria-controls="sidebar" aria-pressed="false" aria-label="Show only icons in the menu"', false)
            // The narrow menu is applied before the page is drawn, so it never flashes wide.
            ->assertSeeInOrder(["localStorage.getItem('myapp.sidebar.narrow')", '</head>'], false);
    }

    public function test_links_at_every_level_show_their_icon(): void
    {
        $this->get('/users')->assertSeeInOrder([
            // Users has no icon of its own, so it gets AMV's default.
            $this->sideLink('Users', 'page'),
            '<span class="material-icon side-icon" aria-hidden="true">settings</span><span class="side-text">Settings</span>',
            // Roles, at level 2, has no icon of its own either; Role reports, at level 3, has one.
            $this->sideLink('Roles'),
            $this->sideLink('Role reports', icon: 'assessment'),
        ], false);

        $this->assertSame(MenuItem::DefaultIcon, (new MenuItem(['Label' => 'Setup']))->iconOrDefault());
        $this->assertSame('account_balance', (new MenuItem(['Label' => 'Setup', 'Icon' => 'account_balance']))->iconOrDefault());
    }

    public function test_headings_toggle_their_links_and_parent_links_get_a_separate_toggle(): void
    {
        $response = $this->get('/users');

        // Settings is a heading, so the whole row is the toggle.
        $response->assertSee(
            'class="side-link side-group" data-submenu-toggle aria-expanded="true" aria-controls="side-sub-'.$this->settings->Id.'"',
            false,
        );
        $response->assertSee('id="side-sub-'.$this->settings->Id.'"', false);

        // Roles is a link with a link under it: the link still goes to /roles, and a separate button opens its group.
        $response->assertSeeInOrder([
            'href="'.url('/roles').'" class="side-link" aria-current="false">',
            'class="side-toggle" aria-label="Roles submenu" data-submenu-toggle aria-expanded="true" aria-controls="side-sub-'.$this->roles->Id.'"',
            'id="side-sub-'.$this->roles->Id.'"',
            '>Role reports</span></a>',
        ], false);

        // Links with nothing under them have no toggle.
        $response->assertDontSee('aria-label="Users submenu"', false);
    }

    public function test_groups_holding_the_current_page_are_marked_to_stay_open(): void
    {
        // On the home page, no group holds the current page, so the script closes them all.
        $this->get('/users')->assertDontSee('data-active-branch', false);

        // On a page under Roles, both Settings and Roles must stay open.
        $this->get('/roles/create')
            ->assertSee('aria-controls="side-sub-'.$this->settings->Id.'" data-active-branch', false)
            ->assertSee('aria-controls="side-sub-'.$this->roles->Id.'" data-active-branch', false);
    }

    public function test_profile_block_shows_who_is_signed_in_with_my_profile_and_log_out(): void
    {
        $this->me->update(['Name' => 'Siti Aminah Binti Ali']);
        $profileUrl = route('profile');

        $this->get('/users')
            ->assertSeeInOrder([
                'aria-controls="side-profile-menu"',
                '<span class="side-avatar" aria-hidden="true">SA</span>',
                '<span class="side-text">Siti Aminah Binti Ali</span>',
                'href="'.$profileUrl.'" class="side-link" '.$this->sideLink('My profile', icon: 'person'),
                '<form method="post" action="'.route('logout').'" class="side-row">',
                '<span class="material-icon side-icon" aria-hidden="true">logout</span><span class="side-text">Log out</span>',
            ], false)
            ->assertDontSee('aria-controls="side-profile-menu" data-active-branch', false);

        // On your own profile, its group stays open with My profile marked.
        $this->get($profileUrl)
            ->assertSee('aria-controls="side-profile-menu" data-active-branch', false)
            ->assertSee('href="'.$profileUrl.'" class="side-link active" aria-current="page">', false);
    }

    public function test_css_javascript_and_images_are_linked_with_their_version(): void
    {
        $version = filemtime(public_path('js/sidebar.js'));

        // A changed file gets a new address, so browsers never keep using an old copy.
        $this->assertSame(asset('js/sidebar.js').'?v='.$version, versioned_asset('js/sidebar.js'));
        $this->assertSame(asset('js/no-such-file.js'), versioned_asset('js/no-such-file.js'));

        $this->get('/users')
            ->assertSee('<link rel="stylesheet" href="'.versioned_asset('css/site.css').'" />', false)
            ->assertSee('<script src="'.versioned_asset('js/sidebar.js').'"></script>', false)
            ->assertSee('<img src="'.versioned_asset('images/logo.png').'"', false);
    }

    public function test_login_page_shows_the_logo_without_the_menu(): void
    {
        auth()->logout();

        $this->get('/login')
            ->assertSee(asset('images/logo.png'), false)
            ->assertDontSee('<aside class="sidebar"', false);
    }

    public function test_the_app_name_and_icon_come_from_the_app_settings(): void
    {
        config(['app.name' => 'Crimeify']);

        // The browser tab shows the name and the icon on every page.
        $this->get('/roles')
            ->assertSee('<title>Roles · Crimeify</title>', false)
            ->assertSee('<link rel="icon" type="image/png" href="'.versioned_asset('images/logo.png').'" />', false)
            ->assertSeeInOrder(['<a class="side-brand"', asset('images/logo.png'), '<span class="side-text">Crimeify</span>'], false);

        auth()->logout();

        $this->get('/login')
            ->assertSee('<title>Log in · Crimeify</title>', false)
            ->assertSee('<link rel="icon" type="image/png" href="'.versioned_asset('images/logo.png').'" />', false)
            ->assertSee('your Crimeify account', false);
    }
}
