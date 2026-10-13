<?php

namespace App\Http\Controllers\Api;

use App\Enums\ClientHistoryCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientHistoryEntryRequest;
use App\Http\Requests\UpdateClientPreferencesRequest;
use App\Models\Client;
use App\Models\ClientHistoryEntry;
use App\Services\ClientHistoryService;
use App\Support\FieldLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Histórico do cliente: resumo + selo de fidelidade, Notas de Preferência e
 * linha do tempo (eventos automáticos + anotações manuais).
 *
 * Acesso: role:admin,attendant,photographer (os mesmos que veem clientes).
 * As anotações são sempre buscadas PELO cliente da rota (scopeBindings):
 * /clients/1/history-entries/9 só encontra a anotação 9 se ela for do
 * cliente 1 — trocar ids na URL dá 404, nunca o registro de outro cliente.
 */
class ClientHistoryController extends Controller
{
    public function __construct(private readonly ClientHistoryService $history) {}

    public function show(Request $request, Client $client): JsonResponse
    {
        return response()->json([
            'data' => [
                'client' => ['id' => $client->id, 'name' => $client->name],
                'summary' => $this->history->summary($client),
                'preference_notes' => $client->preference_notes,
                'timeline' => $this->history->timeline($client, $request->user()),
                'shoots' => $this->history->shootOptions($client),
                'categories' => array_map(fn (ClientHistoryCategory $c) => ['value' => $c->value, 'label' => $c->label()], ClientHistoryCategory::cases()),
                'limits' => [
                    'preference_notes' => FieldLimits::PREFERENCE_NOTES,
                    'title' => FieldLimits::HISTORY_TITLE,
                    'notes' => FieldLimits::HISTORY_NOTES,
                ],
            ],
        ]);
    }

    public function updatePreferences(UpdateClientPreferencesRequest $request, Client $client): JsonResponse
    {
        $client->forceFill(['preference_notes' => $request->validated('preference_notes')])->save();

        return response()->json(['data' => ['preference_notes' => $client->preference_notes]]);
    }

    public function storeEntry(ClientHistoryEntryRequest $request, Client $client): JsonResponse
    {
        $entry = new ClientHistoryEntry($request->entryData());
        $entry->client()->associate($client);
        $entry->author()->associate($request->user());
        $entry->save();

        return response()->json(['data' => $this->history->manualEvent($entry->fresh(['author', 'appointment']), $request->user())], 201);
    }

    public function updateEntry(ClientHistoryEntryRequest $request, Client $client, ClientHistoryEntry $historyEntry): JsonResponse
    {
        Gate::authorize('update', $historyEntry);

        $historyEntry->fill($request->entryData())->save();

        return response()->json(['data' => $this->history->manualEvent($historyEntry->fresh(['author', 'appointment']), $request->user())]);
    }

    public function destroyEntry(Client $client, ClientHistoryEntry $historyEntry): JsonResponse
    {
        Gate::authorize('delete', $historyEntry);

        $historyEntry->delete();

        return response()->json(null, 204);
    }
}
