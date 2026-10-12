<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProfilePhotoRequest;
use App\Http\Requests\UpdateProfilePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\FileStorage;
use App\Support\FileKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Autoatendimento do próprio perfil — sem relação com o CRUD administrativo
 * de usuários (que não existe/não é o objetivo aqui). Todo método usa
 * exclusivamente $request->user(): nunca recebe um user_id do front-end, o
 * que garante que ninguém edita o perfil de outra pessoa.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly FileStorage $files) {}

    public function show(): JsonResponse
    {
        return response()->json($this->present(request()->user()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['name' => $request->validated('name')]);

        return response()->json($this->present($user));
    }

    public function updatePassword(UpdateProfilePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        // Senha trocada = sessões antigas encerradas: revoga todos os tokens
        // do usuário, MENOS o desta requisição (quem trocou continua logado
        // aqui; um aparelho perdido/invasor em outro lugar é desconectado).
        // (Token "transitório" — sessão/testes — não é registro do banco.)
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof SanctumToken, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    public function updatePhoto(StoreProfilePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('photo');

        // Armazenamento persistente (local ou S3) — não mais o disco
        // "public" do servidor, que no Railway seria perdido a cada deploy.
        $path = FileKeys::profilePhoto($file->extension() ?: $file->getClientOriginalExtension());
        $this->files->putFile($path, $file, $file->getMimeType());

        $previous = $user->profile_photo_path;
        $user->update(['profile_photo_path' => $path]);
        $this->deletePhoto($previous);

        return response()->json($this->present($user));
    }

    public function destroyPhoto(): JsonResponse
    {
        $user = request()->user();

        if ($user->profile_photo_path) {
            $previous = $user->profile_photo_path;
            $user->update(['profile_photo_path' => null]);
            $this->deletePhoto($previous);
        }

        return response()->json($this->present($user));
    }

    /**
     * Remove a foto antiga do armazenamento atual e, se ela ainda for do
     * formato antigo, também do disco "public" legado.
     */
    private function deletePhoto(?string $path): void
    {
        if (! $path) {
            return;
        }

        $this->files->delete($path);
        Storage::disk(config('filesystems.snapmanager.legacy_profile_photo_disk', 'public'))->delete($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return $user->toAuthArray();
    }
}
