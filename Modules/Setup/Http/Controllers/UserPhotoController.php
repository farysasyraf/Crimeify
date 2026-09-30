<?php

namespace Modules\Setup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Setup\Support\ProfilePhoto;

/**
 * Profile photos, stored as bytes in dbo.UserPhotos. Your own is on My profile (/profile/photo, in the code); anyone
 * else's is on Edit user (users/photo, users/store-photo and users/destroy-photo, on the Routes page).
 */
class UserPhotoController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        return $this->image($request, $user);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        $this->save($request, $user);

        return redirect(page_url('users/edit', ['user' => $user]))->with('message', "Changed the photo of {$user->Name}.");
    }

    public function destroy(User $user): RedirectResponse
    {
        $user->photo()->delete();

        return redirect(page_url('users/edit', ['user' => $user]))->with('message', "Removed the photo of {$user->Name}.");
    }

    public function showMine(Request $request): Response
    {
        return $this->image($request, $request->user());
    }

    public function storeMine(Request $request): RedirectResponse
    {
        $this->save($request, $request->user());

        return redirect()->route('profile')->with('message', 'Changed your photo.');
    }

    public function destroyMine(Request $request): RedirectResponse
    {
        $request->user()->photo()->delete();

        return redirect()->route('profile')->with('message', 'Removed your photo.');
    }

    /**
     * The photo as an image, or Not found without one.
     */
    private function image(Request $request, User $user): Response
    {
        $photo = $user->photo()->first();

        abort_if($photo === null || ! in_array($photo->ContentType, UserPhoto::Types, true), 404);

        $bytes = is_resource($photo->Photo) ? stream_get_contents($photo->Photo) : $photo->Photo;

        return response($bytes, 200, [
            'Content-Type' => $photo->ContentType,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            // Its address changes with every new photo (…?v=), so the browser can keep it; it's behind the login, so
            // only this browser does.
            'Cache-Control' => $request->has('v') ? 'private, max-age=31536000, immutable' : 'private, no-cache',
        ]);
    }

    private function save(Request $request, User $user): void
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
        ], [
            'photo.required' => 'Choose a photo to upload.',
            'photo.uploaded' => "The photo didn't upload. Try again, or choose a smaller one.",
            'photo.mimes' => 'Choose a JPG or PNG image.',
            'photo.max' => 'Choose an image under 10 MB.',
        ]);

        [$bytes, $type] = ProfilePhoto::fromUpload($request->file('photo'));

        UserPhoto::saveFor($user, $bytes, $type);
    }
}
