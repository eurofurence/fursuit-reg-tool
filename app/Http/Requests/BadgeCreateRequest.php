<?php

namespace App\Http\Requests;

use App\Rules\AllowedPritingCharactersRule;
use App\Services\FursuitImageService;
use Illuminate\Foundation\Http\FormRequest;

class BadgeCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'species' => ['required', 'string', 'max:32', new AllowedPritingCharactersRule],
            'name' => ['required', 'string', 'max:32', new AllowedPritingCharactersRule],
            'image' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png',
                'dimensions:min_width=240,min_height=320',
                'max:8192',
            ],
            // Crop rectangle in the pixel space of the uploaded (EXIF-oriented) image.
            // Optional: without it the server centre-crops to 3:4.
            'crop' => ['nullable', 'array'],
            'crop.x' => ['required_with:crop', 'integer', 'min:0'],
            'crop.y' => ['required_with:crop', 'integer', 'min:0'],
            'crop.width' => ['required_with:crop', 'integer', 'min:'.FursuitImageService::MIN_CROP_WIDTH],
            'crop.height' => ['required_with:crop', 'integer', 'min:'.FursuitImageService::MIN_CROP_HEIGHT],
            'catchEmAll' => ['required', 'boolean'],
            'publish' => ['required', 'boolean'],
            'tos' => ['required', 'accepted'],
            'upgrades.spareCopy' => ['required', 'boolean'],
        ];
    }
}
