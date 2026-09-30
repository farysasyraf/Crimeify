<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The box a notification comes as, which toast.js shows as a toast.
     */
    private function flash(string $status, string $message): string
    {
        $role = $status === 'error' ? 'alert' : 'status';

        return '<div class="alert alert-'.$status.' flash" role="'.$role.'" data-toast="'.$status.'">'.e($message).'</div>';
    }

    public function test_every_page_and_the_login_page_load_the_toasts(): void
    {
        $this->assertFileExists(public_path('js/toast.js'));

        $this->get('/login')->assertSeeInOrder([
            "document.documentElement.classList.add('js');",
            '<script src="'.versioned_asset('js/vendor/sweetalert2.all.min.js').'" defer></script>',
            '<script src="'.versioned_asset('js/toast.js').'" defer></script>',
        ], false);

        $this->signIn();
        $this->get('/users')->assertSeeInOrder([
            '<script src="'.versioned_asset('js/vendor/sweetalert2.all.min.js').'" defer></script>',
            '<script src="'.versioned_asset('js/confirm.js').'" defer></script>',
            '<script src="'.versioned_asset('js/toast.js').'" defer></script>',
        ], false);
    }

    public function test_what_a_save_did_is_a_success_toast(): void
    {
        $this->signIn();

        $this->followingRedirects()
            ->post('/roles/store', ['name' => 'Editor'])
            ->assertSee($this->flash('success', 'Added the Editor role.'), false);

        // Only once: the next page has none.
        $this->get('/roles')->assertDontSee('data-toast=', false);
    }

    public function test_a_controller_can_say_how_it_went(): void
    {
        $this->signIn();

        $this->withSession(['message' => 'Nothing to save.', 'status' => 'info'])->get('/users')->assertSee($this->flash('info', 'Nothing to save.'), false);
        $this->withSession(['message' => 'Check this.', 'status' => 'warning'])->get('/users')->assertSee($this->flash('warning', 'Check this.'), false);
        $this->withSession(['message' => 'It broke.', 'status' => 'error'])->get('/users')->assertSee($this->flash('error', 'It broke.'), false);
        $this->withSession(['message' => 'Odd.', 'status' => '<b>'])->get('/users')->assertSee($this->flash('info', 'Odd.'), false);
    }

    public function test_a_form_with_problems_is_an_error_toast_with_the_first_one(): void
    {
        $this->signIn();

        $this->from('/roles/create')->post('/roles/store', ['name' => '']);
        $this->get('/roles/create')->assertSee($this->flash('error', 'The name field is required.'), false);

        $this->from('/users/create')->post('/users/store', ['name' => '', 'email' => 'not-an-email']);
        $this->get('/users/create')->assertSee($this->flash('error', 'The name field is required. And 2 more to fix below.'), false);
    }

    public function test_logging_in_and_out_say_how_it_went(): void
    {
        $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'wrong-password']);
        $this->get('/login')->assertSee('data-toast="error"', false);

        $this->signIn();
        $this->followingRedirects()->post('/logout')->assertSee($this->flash('success', "You've logged out."), false);
    }

    public function test_answering_no_says_nothing_was_changed(): void
    {
        $script = file_get_contents(public_path('js/confirm.js'));

        $this->assertStringContainsString("window.toast?.('info', 'Cancelled. Nothing was changed.');", $script);
    }
}
