<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    /** Profile photos: 3 MB max, JPG / PNG / WEBP only. Stored on the private "local" disk. */
    private const AVATAR_MAX_KB = 3072;
    private const AVATAR_DIR = 'avatars';
    private const AVATAR_SIZE = 256;
    private const AVATAR_MAX_PIXELS = 25_000_000;

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Upload / replace the profile photo (POST /profile/avatar).
     * The image is re-encoded as a 256x256 square JPEG when GD is available, which also strips metadata.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => [
                'required', 'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.self::AVATAR_MAX_KB,
            ],
        ], [
            'avatar.required' => 'Choose a photo first.',
            'avatar.uploaded' => 'The upload failed. Try a smaller image.',
            'avatar.max' => 'The photo must be 3 MB or smaller.',
            'avatar.mimes' => 'The photo must be a JPG, PNG or WEBP image.',
            'avatar.mimetypes' => 'The photo must be a JPG, PNG or WEBP image.',
        ]);

        $real = $request->file('avatar')->getRealPath();
        $info = @getimagesize($real);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

        if (! $info || ! isset($types[$info[2]])) {
            throw ValidationException::withMessages(['avatar' => 'The photo must be a JPG, PNG or WEBP image.']);
        }

        if ($info[0] * $info[1] > self::AVATAR_MAX_PIXELS) {
            throw ValidationException::withMessages(['avatar' => 'That image is too large. Choose a smaller one.']);
        }

        $user = $request->user();
        $base = $user->id.'_'.Str::lower(Str::random(16));

        if ($this->canResize($info[2])) {
            $data = $this->squareJpeg($real, $info[2]);
            if ($data === null) {
                throw ValidationException::withMessages(['avatar' => 'That image could not be read. Try another one.']);
            }
            $name = $base.'.jpg';
        } else {
            // No GD on this PHP: keep the checked original.
            $data = (string) file_get_contents($real);
            $name = $base.'.'.$types[$info[2]];
        }

        Storage::disk('local')->put(self::AVATAR_DIR.'/'.$name, $data);

        $old = $user->avatar_path;
        $user->avatar_path = $name;
        $user->save();

        $this->deleteAvatarFile($old);

        return Redirect::route('profile.edit')->with('status', 'avatar-updated');
    }

    /** Remove the profile photo (DELETE /profile/avatar). The initial letter shows again. */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $old = $user->avatar_path;

        $user->avatar_path = null;
        $user->save();

        $this->deleteAvatarFile($old);

        return Redirect::route('profile.edit')->with('status', 'avatar-removed');
    }

    /**
     * Stream a profile photo (GET /avatars/{user}, name: avatars.show). Signed-in users only; the file sits on
     * the private disk, never behind a public URL. The URL carries ?v=<hash of the file name>, so a new photo
     * gets a new URL and the browser cache can be long.
     */
    public function avatar(User $user): Response
    {
        abort_unless($user->avatar_path, 404);

        $relative = self::AVATAR_DIR.'/'.basename($user->avatar_path);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($relative), 404);

        $mime = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        ][strtolower(pathinfo($relative, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return $disk->response($relative, null, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $avatar = $user->avatar_path;

        Auth::logout();

        $user->delete();
        $this->deleteAvatarFile($avatar);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function deleteAvatarFile(?string $name): void
    {
        if ($name) {
            Storage::disk('local')->delete(self::AVATAR_DIR.'/'.basename($name));
        }
    }

    private function canResize(int $type): bool
    {
        $loader = $this->loaderFor($type);

        return $loader !== null && function_exists($loader) && function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
    }

    private function loaderFor(int $type): ?string
    {
        return [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
        ][$type] ?? null;
    }

    /** Center-crop to a square, scale to 256 px, return JPEG bytes (null when the image cannot be decoded). */
    private function squareJpeg(string $path, int $type): ?string
    {
        $loader = $this->loaderFor($type);
        $src = @$loader($path);

        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);

        $dst = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG/WEBP transparency -> white
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::AVATAR_SIZE, self::AVATAR_SIZE, $side, $side);

        ob_start();
        imagejpeg($dst, null, 88);
        $data = ob_get_clean();

        return $data !== false && $data !== '' ? $data : null;
    }
}
