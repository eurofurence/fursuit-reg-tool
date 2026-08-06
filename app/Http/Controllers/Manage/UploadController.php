<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One endpoint for every file a manage form can attach.
 *
 * The stored path is flashed back and the form field then submits it as an ordinary
 * field value, so uploads stay inside the Inertia request cycle. No JSON API.
 *
 * The disk is s3 with private visibility, which is what every read site in the panel
 * already assumes. FursuitResource's FileUpload::make('image') has no ->disk() call, so
 * it writes to the default filesystem disk while the table column, the infolist entry
 * and DbService all read from s3; the two only coincide because config/filesystems.php
 * currently defaults to s3. This endpoint names the disk instead of inheriting it.
 */
class UploadController extends Controller
{
    /**
     * Purpose determines disk, directory, visibility, accepted mime types and max size.
     * Phase 1 needs fursuit_image only; later phases add rows here.
     *
     * The mime and size limits match BadgeCreateRequest, so an operator replacing an
     * attendee's image cannot store something the attendee could not have submitted.
     *
     * @var array<string, array{disk: string, directory: string, visibility: string, mimes: array<int, string>, max: int, preserve_filename: bool}>
     */
    private const PURPOSES = [
        'fursuit_image' => [
            'disk' => 's3',
            'directory' => 'fursuits',
            'visibility' => 'private',
            'mimes' => ['jpeg', 'jpg', 'png'],
            'max' => 8192,
            'preserve_filename' => false,
        ],
    ];

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'purpose' => ['required', Rule::in(array_keys(self::PURPOSES))],
        ]);

        $purpose = $request->string('purpose')->toString();
        $config = self::PURPOSES[$purpose];

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', $config['mimes']),
                'max:'.$config['max'],
            ],
        ]);

        $file = $request->file('file');

        $name = $config['preserve_filename']
            ? Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension()
            : Str::random(40).'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs($config['directory'], $name, [
            'disk' => $config['disk'],
            'visibility' => $config['visibility'],
        ]);

        Toast::put('upload', [
            'purpose' => $purpose,
            'path' => $path,
            'url' => $this->previewUrl($config['disk'], $config['visibility'], $path),
        ]);

        Toast::flashSuccess('File uploaded', $name);

        return back();
    }

    /**
     * Temporary signed URL for a stored file, falling back to a plain URL.
     */
    private function previewUrl(string $disk, string $visibility, string $path): ?string
    {
        $storage = Storage::disk($disk);

        if ($visibility === 'private') {
            // The local and public drivers have the method but throw when they
            // cannot sign, which is what a test or a local dev disk will do.
            try {
                return $storage->temporaryUrl($path, now()->addMinutes(15));
            } catch (\Throwable) {
                return null;
            }
        }

        return $storage->url($path);
    }
}
