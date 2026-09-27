<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGalleryImagesRequest extends FormRequest
{
    /**
     * Maximum images allowed per gallery (initial version, no pagination on the grid yet).
     */
    public const MAX_IMAGES_PER_GALLERY = 50;

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
     * "mimetypes" inspects the actual file content (fileinfo), not just the
     * extension, so a renamed .php file can't slip through as a .jpg.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1'],
            'images.*' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $gallery = $this->route('gallery');

            if (! $gallery) {
                return;
            }

            $incoming = count($this->file('images', []));
            $existing = $gallery->images()->count();

            if ($existing + $incoming > self::MAX_IMAGES_PER_GALLERY) {
                $validator->errors()->add(
                    'images',
                    sprintf(
                        'Esta galeria já possui %d foto(s); o limite é de %d fotos por galeria (tentando enviar mais %d).',
                        $existing,
                        self::MAX_IMAGES_PER_GALLERY,
                        $incoming
                    )
                );
            }
        });
    }
}
