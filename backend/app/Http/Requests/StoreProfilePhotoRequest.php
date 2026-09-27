<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mesma estratégia de validação do upload de fotos de galeria
     * (StoreGalleryImagesRequest): "mimetypes" inspeciona o conteúdo real do
     * arquivo (fileinfo), não só a extensão — um .php renomeado pra .jpg não
     * passa. Limite de 2MB (foto de perfil, não precisa da mesma folga de
     * 10MB das fotos de trabalho).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ];
    }
}
