<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectGalleryImagesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The "exists" rule is scoped to gallery_id so an id belonging to a
     * different gallery can never be selected through this token.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $gallery = $this->route('gallery');

        return [
            'image_ids' => ['present', 'array', 'max:5000'],
            'image_ids.*' => [
                'integer',
                Rule::exists('gallery_images', 'id')->where('gallery_id', $gallery?->id),
            ],
        ];
    }
}
