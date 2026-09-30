<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\User;
use App\Models\UserPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->signIn();
    }

    /**
     * A JPG or PNG drawn with GD: the left half red, the right half blue, and, for a PNG, a see-through top-left
     * corner. With $orientation, it carries the note phones add to say which way up it goes (EXIF Orientation).
     */
    private function photo(int $width, int $height, string $type = 'jpg', ?int $orientation = null): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 220, 30, 30));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 30, 220));

        ob_start();
        if ($type === 'png') {
            imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, 9, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagepng($image);
        } else {
            imagejpeg($image, null, 95);
        }
        $bytes = ob_get_clean();

        if ($orientation !== null) {
            // A minimal EXIF block holding only the Orientation tag (0x0112), put right after the JPEG's start.
            $tiff = "II\x2A\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00\x00\x00\x00\x00";
            $exif = "Exif\x00\x00".$tiff;
            $bytes = "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
        }

        $path = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, "me.{$type}", $type === 'png' ? 'image/png' : 'image/jpeg', null, true);
    }

    /**
     * The stored photo as a GD image, with its type.
     *
     * @return array{0: \GdImage, 1: string}
     */
    private function stored(User $user): array
    {
        $photo = UserPhoto::findOrFail($user->Id);

        return [imagecreatefromstring($photo->Photo), $photo->ContentType];
    }

    /**
     * Whether the pixel is mostly red, blue, or see-through.
     */
    private function colourAt(\GdImage $image, int $x, int $y): string
    {
        $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return match (true) {
            $pixel['alpha'] > 100 => 'clear',
            $pixel['red'] > $pixel['blue'] => 'red',
            default => 'blue',
        };
    }

    public function test_my_profile_shows_my_photo_box_and_details_like_the_design(): void
    {
        $this->me->update(['Phone' => '(219) 555-0114']);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('<script src="'.versioned_asset('js/profile-photo.js').'" defer></script>', false)
            ->assertSeeInOrder([
                '<h1>Edit profile</h1>',
                // No photo yet: the initials in its place.
                '<span class="profile-avatar profile-initials" aria-hidden="true">SI</span>',
                'action="'.route('profile.photo.store').'" enctype="multipart/form-data"',
                'Upload new photo',
                '<input type="file" name="photo" accept="image/jpeg,image/png" required',
                'At least 800×800 px recommended.<br />JPG or PNG is allowed.',
                '<h2 id="profile-info">Personal info</h2>',
                '<dt>Full name</dt><dd>Signed In</dd>',
                '<dt>Email</dt><dd>me@example.com</dd>',
                '<dt>Phone</dt><dd>(219) 555-0114</dd>',
                '<h2 id="profile-password">Password</h2>',
                '<h2 id="profile-roles">Roles</h2>',
                '<span class="badge badge-role">ADMIN</span>',
            ], false)
            ->assertDontSee('Remove</button>', false);
    }

    public function test_a_photo_is_stored_as_bytes_as_the_middle_square_at_most_800_px(): void
    {
        $this->post('/profile/photo', ['photo' => $this->photo(1600, 1000)])
            ->assertRedirect('/profile')
            ->assertSessionHas('message', 'Changed your photo.');

        [$image, $type] = $this->stored($this->me);
        $this->assertSame('image/jpeg', $type);
        $this->assertSame([800, 800], [imagesx($image), imagesy($image)]);
        // The middle square of a 1600×1000 image is 300 to 1300 across: red, then blue from the middle.
        $this->assertSame(['red', 'blue'], [$this->colourAt($image, 200, 400), $this->colourAt($image, 600, 400)]);

        // The page, and the menu, show it, at an address that changes with each new photo.
        $version = $this->me->fresh()->photoVersion();
        $address = route('profile.photo', ['v' => $version]);
        $this->get('/profile')
            ->assertSee('<img class="profile-avatar" src="'.$address.'" alt="Photo of Signed In" width="120" height="120" />', false)
            ->assertSee('<img class="side-avatar" src="'.$address.'" alt="" width="30" height="30" />', false)
            ->assertSee('Remove</button>', false);

        $response = $this->get($address)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(UserPhoto::find($this->me->Id)->Photo, $response->getContent());
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_a_small_png_is_not_enlarged_and_keeps_what_is_see_through(): void
    {
        $this->post('/profile/photo', ['photo' => $this->photo(300, 200, 'png')])->assertSessionHasNoErrors();

        [$image, $type] = $this->stored($this->me);
        $this->assertSame('image/png', $type);
        $this->assertSame([200, 200], [imagesx($image), imagesy($image)]);
        $this->assertSame('clear', $this->colourAt($image, 10, 2));
        $this->assertSame('red', $this->colourAt($image, 10, 100));
    }

    public function test_a_phone_photo_is_turned_the_way_up_it_was_taken(): void
    {
        // Saved on its side, red on the left, with a note to turn it a quarter clockwise: upright, red is on top.
        $this->post('/profile/photo', ['photo' => $this->photo(400, 200, orientation: 6)])->assertSessionHasNoErrors();

        [$image] = $this->stored($this->me);
        $this->assertSame([200, 200], [imagesx($image), imagesy($image)]);
        $this->assertSame(['red', 'blue'], [$this->colourAt($image, 180, 20), $this->colourAt($image, 20, 180)]);
    }

    public function test_only_a_jpg_or_png_image_is_taken(): void
    {
        $this->post('/profile/photo', [])->assertSessionHasErrors(['photo' => 'Choose a photo to upload.']);
        $this->post('/profile/photo', ['photo' => UploadedFile::fake()->image('me.gif', 100, 100)])
            ->assertSessionHasErrors(['photo' => 'Choose a JPG or PNG image.']);
        $this->post('/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('me.jpg', 'not an image at all')])
            ->assertSessionHasErrors(['photo' => 'Choose a JPG or PNG image.']);
        $this->post('/profile/photo', ['photo' => UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg')])
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, UserPhoto::count());
        $this->get('/profile/photo')->assertNotFound();
    }

    public function test_removing_the_photo_brings_back_the_initials(): void
    {
        $this->post('/profile/photo', ['photo' => $this->photo(900, 900)]);
        $this->get('/profile')->assertSee(route('profile.photo.destroy'), false);

        $this->delete('/profile/photo')->assertRedirect('/profile')->assertSessionHas('message', 'Removed your photo.');

        $this->assertNull(UserPhoto::find($this->me->Id));
        $this->get('/profile')->assertSee('<span class="profile-avatar profile-initials" aria-hidden="true">SI</span>', false);
        $this->get('/profile/photo')->assertNotFound();
    }

    public function test_an_administrator_changes_another_users_photo_on_edit_user(): void
    {
        $other = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);
        $edit = "/users/edit/{$other->Id}";

        $this->get($edit)->assertSee('<span class="profile-avatar profile-initials" aria-hidden="true">SA</span>', false)
            ->assertSee('action="'.url("/users/store-photo/{$other->Id}").'"', false);

        $this->post("/users/store-photo/{$other->Id}", ['photo' => $this->photo(900, 900)])
            ->assertRedirect($edit)
            ->assertSessionHas('message', 'Changed the photo of Siti Aminah.');

        $this->assertNotNull(UserPhoto::find($other->Id));
        $this->assertNull(UserPhoto::find($this->me->Id));
        $address = url("/users/photo/{$other->Id}").'?v='.$other->photoVersion();
        $this->get($edit)->assertSee('<img class="profile-avatar" src="'.$address.'" alt="Photo of Siti Aminah"', false);
        $this->get($address)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->delete("/users/destroy-photo/{$other->Id}")->assertRedirect($edit)->assertSessionHas('message', 'Removed the photo of Siti Aminah.');
        $this->assertNull(UserPhoto::find($other->Id));
        $this->get("/users/photo/{$other->Id}")->assertNotFound();

        // Deleting a user deletes their photo.
        $this->post("/users/store-photo/{$other->Id}", ['photo' => $this->photo(900, 900)]);
        $this->delete("/users/destroy/{$other->Id}");
        $this->assertSame(0, UserPhoto::count());
    }

    public function test_without_the_role_only_your_own_photo_can_be_changed(): void
    {
        $other = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);
        UserPhoto::saveFor($other, 'photo bytes', 'image/jpeg');
        $demo = User::create(['Name' => 'Demo', 'Email' => 'demo@example.com', 'Password' => 'correct-horse']);
        $this->actingAs($demo);

        $this->get("/users/photo/{$other->Id}")->assertForbidden();
        $this->post("/users/store-photo/{$other->Id}", ['photo' => $this->photo(900, 900)])->assertForbidden();
        $this->delete("/users/destroy-photo/{$other->Id}")->assertForbidden();
        $this->assertSame('photo bytes', UserPhoto::find($other->Id)->Photo);

        $this->post('/profile/photo', ['photo' => $this->photo(900, 900)])->assertRedirect('/profile');
        $this->assertNotNull(UserPhoto::find($demo->Id));
    }
}
