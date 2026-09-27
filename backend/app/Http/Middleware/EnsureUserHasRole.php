<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorização por perfil ("o que o usuário pode fazer"), separada da
 * autenticação do Sanctum ("quem é o usuário" — já garantida pelo
 * middleware `auth:sanctum` antes deste rodar). Uso nas rotas:
 *
 *   Route::middleware('role:admin,attendant')->group(...);
 *
 * Mesmo que o front-end esconda um botão, uma requisição direta de um
 * usuário sem o perfil exigido recebe 403 aqui — a regra real vive no
 * back-end, não na UI.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $userRole = $request->user()?->role;

        if (! $userRole || ! in_array($userRole->value, $roles, true)) {
            abort(403, 'Você não possui permissão para executar esta ação.');
        }

        return $next($request);
    }
}
