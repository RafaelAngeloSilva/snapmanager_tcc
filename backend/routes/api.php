<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CepController;
use App\Http\Controllers\Api\ClicksignWebhookController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentSignatureController;
use App\Http\Controllers\Api\DocumentSignerController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\KanbanBoardController;
use App\Http\Controllers\Api\KanbanColumnController;
use App\Http\Controllers\Api\KanbanTaskController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderTrackingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicGalleryController;
use App\Http\Controllers\Api\PublicOrderTrackingController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/auth/google/status', [GoogleAuthController::class, 'status']);

Route::prefix('public/galleries/{gallery:access_token}')->group(function () {
    Route::get('/', [PublicGalleryController::class, 'show']);
    Route::get('/images/{image}', [PublicGalleryController::class, 'showImageFile']);
    Route::get('/images/{image}/thumbnail', [PublicGalleryController::class, 'showThumbnailFile']);
    Route::post('/select', [PublicGalleryController::class, 'selectImages']);
});

/*
| Acompanhe seu Pedido — página pública do cliente, sem login. O acesso é
| pelo token exclusivo do pedido (revogável pela equipe). Throttle contra
| tentativa de adivinhar tokens.
*/
Route::get('/public/tracking/{token}', [PublicOrderTrackingController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->middleware('throttle:60,1');

/*
| Webhook da Clicksign — chamado pela Clicksign, não por um usuário logado,
| por isso fica FORA do auth:sanctum. A autenticidade é verificada por HMAC
| (header Content-Hmac) no ClicksignWebhookController: sem HMAC válido, nada
| é processado. O throttle só limita abuso de volume.
*/
Route::post('/webhooks/clicksign', ClicksignWebhookController::class)->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| Rotas internas — autenticação (auth:sanctum) + autorização (role:...)
|--------------------------------------------------------------------------
|
| auth:sanctum responde "quem é o usuário" (401 se não houver token válido).
| role:<perfis> responde "o que esse usuário pode fazer" (403 se o perfil
| autenticado não estiver na lista) — ver matriz completa no README, seção
| "Segurança e autorização". As duas coisas são deliberadamente camadas
| distintas: qualquer rota aqui dentro já passou pela autenticação; o role
| middleware só entra quando a ação exige um perfil específico.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Autoatendimento do próprio perfil — sem gate de role: os 4 perfis
    // gerenciam os próprios dados/senha/foto igualmente. ProfileController
    // só opera sobre $request->user(), nunca sobre um id vindo do request.
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto']);
    Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto']);

    // Leitura de clientes/agenda: também liberada para o fotógrafo (precisa
    // consultar quem é o cliente e quando é a sessão). Editor não tem
    // motivo para acessar nenhum dos dois — não participa do atendimento
    // nem da agenda.
    Route::middleware('role:admin,attendant,photographer')->group(function () {
        Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
        Route::apiResource('appointments', AppointmentController::class)->only(['index', 'show']);
    });

    // Gestão de clientes/agenda/pedidos (criar/editar) e utilitários dos
    // formulários (lista de profissionais, CEP) — perfil de atendimento.
    Route::middleware('role:admin,attendant')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/cep/{cep}', [CepController::class, 'show']);

        Route::apiResource('clients', ClientController::class)->except(['index', 'show']);
        Route::apiResource('appointments', AppointmentController::class)->except(['index', 'show']);

        Route::apiResource('orders', OrderController::class)->only(['store', 'update']);

        // Acompanhe seu Pedido: ativar/revogar o link público do cliente —
        // mesmo perfil que gerencia os pedidos (atendimento).
        Route::post('/orders/{order}/tracking', [OrderTrackingController::class, 'enable']);
        Route::delete('/orders/{order}/tracking', [OrderTrackingController::class, 'revoke']);

        // Levar um agendamento/pedido existente para o quadro é uma ação de
        // atendimento (quem organiza a agenda e os pedidos), por isso fica
        // no mesmo grupo que gerencia esses recursos.
        Route::post('/appointments/{appointment}/kanban', [KanbanTaskController::class, 'storeFromAppointment']);
        Route::post('/orders/{order}/kanban', [KanbanTaskController::class, 'storeFromOrder']);

        // Documentos e assinaturas: criar, editar rascunho, signatários,
        // enviar, notificar, sincronizar e baixar os PDFs.
        Route::get('/documents/link-options', [DocumentController::class, 'linkOptions']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::put('/documents/{document}', [DocumentController::class, 'update']);
        Route::get('/documents/{document}/file', [DocumentController::class, 'file']);
        // PDF que vai para a Clicksign (convertido, se o original é Word) e
        // nova tentativa de conversão de um Word em rascunho.
        Route::get('/documents/{document}/signing-file', [DocumentController::class, 'signingFile']);
        Route::post('/documents/{document}/convert', [DocumentController::class, 'retryConversion']);
        Route::post('/documents/{document}/signers', [DocumentSignerController::class, 'store']);
        Route::delete('/documents/{document}/signers/{signer}', [DocumentSignerController::class, 'destroy'])->scopeBindings();
        Route::post('/documents/{document}/send', [DocumentSignatureController::class, 'send']);
        Route::post('/documents/{document}/notify', [DocumentSignatureController::class, 'notify']);
        Route::post('/documents/{document}/sync', [DocumentSignatureController::class, 'sync']);
        Route::post('/documents/{document}/discard-failed-send', [DocumentSignatureController::class, 'discardFailedSend']);
        Route::get('/documents/{document}/download-signed', [DocumentSignatureController::class, 'downloadSigned']);

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/alerts', [DashboardController::class, 'alerts']);
        Route::get('/dashboard/report', [DashboardController::class, 'report']);
        Route::get('/dashboard/charts', [DashboardController::class, 'charts']);
    });

    // Leitura de pedidos e galerias, e a troca de etapa/status do pedido:
    // aberta aos 4 perfis — é exatamente o que fotógrafo (captura) e editor
    // (edição/revisão) precisam para fazer seu trabalho no fluxo de
    // produção, sem dar acesso a clientes/agenda/exclusão.
    Route::middleware('role:admin,attendant,photographer,editor')->group(function () {
        Route::apiResource('orders', OrderController::class)->only(['index', 'show']);
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

        /*
        | Quadro de produção (Kanban)
        |
        | O quadro é compartilhado pelos 4 perfis — é a tela onde a produção
        | acontece. A diferença de permissão dentro dele não é por perfil
        | apenas, e sim por REGISTRO ("fotógrafo mexe nos cards dele"), então
        | as ações sobre tarefas são autorizadas por KanbanTaskPolicy, não
        | por middleware. Já a estrutura do quadro (colunas) é decisão
        | administrativa e fica sob role:admin, mais abaixo.
        */
        Route::get('/kanban', [KanbanBoardController::class, 'index']);
        Route::post('/kanban/tasks', [KanbanTaskController::class, 'store']);
        Route::get('/kanban/tasks/{task}', [KanbanTaskController::class, 'show']);
        Route::put('/kanban/tasks/{task}', [KanbanTaskController::class, 'update']);
        Route::delete('/kanban/tasks/{task}', [KanbanTaskController::class, 'destroy']);
        Route::patch('/kanban/tasks/{task}/move', [KanbanTaskController::class, 'move']);

        // Documentos: leitura para os 4 perfis, mas QUAIS documentos cada um
        // vê é decidido por registro (Document::visibleTo + DocumentPolicy).
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);

        Route::apiResource('galleries', GalleryController::class)->only(['index', 'show']);
        Route::get('/galleries/{gallery}/images/{image}/file', [GalleryController::class, 'showImageFile']);
        Route::get('/galleries/{gallery}/images/{image}/thumbnail', [GalleryController::class, 'showThumbnailFile']);
        Route::get('/galleries/{gallery}/watermark-image/file', [GalleryController::class, 'showWatermarkImageFile']);
    });

    // Exclusão de pedidos e toda a gestão de galerias (criar, editar,
    // encerrar, enviar/remover fotos, marca d'água): só admin. Nem attendant
    // tem essa responsabilidade hoje — é quem monta o link e acompanha a
    // escolha do cliente, mas a operação sensível (dados do cliente saindo
    // do estúdio) fica concentrada no perfil administrativo.
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('orders', OrderController::class)->only(['destroy']);

        // Ações irreversíveis sobre documentos: cancelar o processo na
        // Clicksign e excluir rascunho.
        Route::post('/documents/{document}/cancel', [DocumentSignatureController::class, 'cancel']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

        Route::apiResource('galleries', GalleryController::class)->except(['index', 'show']);
        Route::patch('/galleries/{gallery}/close', [GalleryController::class, 'close']);
        Route::post('/galleries/{gallery}/images', [GalleryController::class, 'uploadImages']);
        Route::delete('/galleries/{gallery}/images/{image}', [GalleryController::class, 'destroyImage']);
        Route::post('/galleries/{gallery}/watermark-image', [GalleryController::class, 'uploadWatermarkImage']);

        // Estrutura do quadro: criar/renomear/reordenar/excluir colunas.
        // Define como o estúdio organiza a produção — decisão do admin.
        Route::post('/kanban/columns', [KanbanColumnController::class, 'store']);
        Route::patch('/kanban/columns/reorder', [KanbanColumnController::class, 'reorder']);
        Route::put('/kanban/columns/{column}', [KanbanColumnController::class, 'update']);
        Route::delete('/kanban/columns/{column}', [KanbanColumnController::class, 'destroy']);
    });
});
