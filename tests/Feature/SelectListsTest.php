<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelectListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_turns_its_lists_into_select2_boxes(): void
    {
        $this->signIn();

        // jQuery and Select2, the versions amv4local uses, served from this app with their licences.
        foreach (['vendor/jquery/jquery.min.js', 'vendor/jquery/LICENSE.txt', 'vendor/select2/select2.min.js',
            'vendor/select2/select2.min.css', 'vendor/select2/LICENSE.md', 'js/select2-init.js'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        $this->assertStringStartsWith('/*! jQuery v3.7.1', file_get_contents(public_path('vendor/jquery/jquery.min.js')));
        $this->assertStringStartsWith('/*! Select2 4.1.0-rc.0', file_get_contents(public_path('vendor/select2/select2.min.js')));

        // Select2's styles come before the app's, which give its lists the app's look, and the scripts in the order
        // they need each other.
        $this->get('/users')->assertSeeInOrder([
            '<link rel="stylesheet" href="'.versioned_asset('vendor/select2/select2.min.css').'" />',
            '<link rel="stylesheet" href="'.versioned_asset('css/site.css').'" />',
            '<script src="'.versioned_asset('vendor/jquery/jquery.min.js').'" defer></script>',
            '<script src="'.versioned_asset('vendor/select2/select2.min.js').'" defer></script>',
            '<script src="'.versioned_asset('js/select2-init.js').'" defer></script>',
            '</body>',
        ], false);
    }

    public function test_lists_whose_first_choice_is_a_prompt_show_it_as_a_placeholder(): void
    {
        $this->signIn();
        $reports = MenuItem::create(['Label' => 'Reports', 'SortOrder' => 1]);
        MenuItem::create(['Label' => 'Monthly', 'Url' => '/reports', 'SortOrder' => 1, 'ParentId' => $reports->Id]);

        $this->get('/routes')
            ->assertSee('<select id="menu_item_id" name="menu_item_id" required data-placeholder="Choose…"', false)
            ->assertSee('<option value="">Choose…</option>', false);

        // "None" in the Link list is a choice of its own rather than a prompt, so it stays an ordinary choice.
        $this->get('/menu-items/create')
            ->assertSee('<select id="parent_for_level_2" name="parent_for_level_2" data-placeholder="Choose a level 1 link…"', false)
            ->assertSee('<select id="parent_for_level_3" name="parent_for_level_3" data-placeholder="Choose a level 2 link…"', false)
            ->assertSee('<select id="url" name="url" aria-describedby="url-hint"', false);

        // The Crime data page's filters show what's listed while none is chosen, and can be cleared back to it.
        $this->get('/crime-data')->assertSeeInOrder([
            'data-placeholder="Choose…"', 'data-placeholder="Choose…"',
            'data-placeholder="All states"', 'data-placeholder="All districts"', 'data-placeholder="All years"', 'data-placeholder="All types"',
        ], false);
    }
}
