<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProfilePhotoRequest;
use App\Http\Requests\UpdateProfilePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Autoatendimento do próprio perfil — sem relação com o CRUD administrativo
 * de usuários (que não existe/não é o objetivo aqui). Todo método usa
 * exclusivamente $request->user(): nunca recebe um user_id do front-end, o
 * que garante que ninguém edita o perfil de outra pessoa.
 */
class ProfileController extends Controller
{
    private const DISK = 'public';

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
        $request->user()->update(['password' => $request->validated('password')]);

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    public function updatePhoto(StoreProfilePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('photo');

        $storedName = Str::uuid()->toString().'.'.($file->extension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs('profile-photos', $storedName, self::DISK);

        if ($user->profile_photo_path) {
            Storage::disk(self::DISK)->delete($user->profile_photo_path);
        }

        $user->update(['profile_photo_path' => $path]);

        return response()->json($this->present($user));
    }

    public function destroyPhoto(): JsonResponse
    {
        $user = request()->user();

        if ($user->profile_photo_path) {
            Storage::disk(self::DISK)->delete($user->profile_photo_path);
            $user->update(['profile_photo_path' => null]);
        }

        return response()->json($this->present($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role', 'profile_photo_url']);
    }
}
