<?php

use App\Http\Controllers\Api\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Ficam em web.php (não api.php) de propósito: são navegações de página
// inteira do navegador até o Google e de volta, não chamadas XHR — o
// Socialite precisa do middleware de sessão do grupo "web" pra guardar e
// validar o "state" (proteção contra CSRF/state injection).
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// SnapManager é uma API pura (sem login via sessão web). Esta rota existe só
// como fallback nomeado para o middleware auth:sanctum não quebrar quando
// route('login') é resolvida para requisições sem header Accept: application/json.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
