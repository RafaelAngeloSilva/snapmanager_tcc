<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StoredFileNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a foto de perfil. A rota exige URL ASSINADA (gerada em
 * User::profile_photo_url, só para quem já recebeu os dados do usuário por
 * uma rota autenticada) — sem assinatura válida, 403. O arquivo vem do
 * FileStorage (local ou S3 privado); o navegador nunca fala com o S3.
 */
class ProfilePhotoController extends Controller
{
    private const CACHE = 'private, max-age=3600';

    /**
     * `$user` é o id cru (sem route model binding): a assinatura é validada
     * ANTES de consultar o banco, então uma URL adulterada recebe sempre 403 —
     * não dá para descobrir quais ids existem.
     */
    public function show(int $user, FileStorage $files): Response
    {
        $path = User::query()->whereKey($user)->value('profile_photo_path');

        abort_unless($path, 404);

        $name = 'foto-perfil.'.pathinfo($path, PATHINFO_EXTENSION);

        try {
            return $files->response($path, $name, headers: ['Cache-Control' => self::CACHE]);
        } catch (StoredFileNotFoundException $e) {
            // Fotos enviadas antes desta mudança ficavam no disco "public"
            // (storage/app/public). Continuam aparecendo no desenvolvimento
            // até serem migradas (php artisan storage:migrate-to-s3).
            $legacy = Storage::disk(config('filesystems.snapmanager.legacy_profile_photo_disk', 'public'));

            if (FileStorage::assertSafeKey($path) && $legacy->exists($path)) {
                return $legacy->response($path, $name, ['Cache-Control' => self::CACHE]);
            }

            throw $e;
        }
    }
}
