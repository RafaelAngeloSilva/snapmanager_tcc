<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->query('search');

        return ClientResource::collection(
            Client::query()
                ->when($search, function ($query, $search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%")
                            ->orWhere('document', 'ilike', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString()
        );
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return ClientResource::make($client)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        return ClientResource::make($client);
    }

    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $client->update($request->validated());

        return ClientResource::make($client);
    }

    public function destroy(Client $client): JsonResponse
    {
        // Soft delete: o registro só recebe "deleted_at" (UPDATE), não é
        // removido fisicamente. Por isso a antiga restrição de FK (que
        // bloqueava com 409 quando havia agendamentos/pedidos vinculados)
        // nunca mais é acionada — agendamentos e pedidos continuam existindo
        // normalmente, apenas com a relação "client" resolvendo como null.
        $client->delete();

        return response()->json(null, 204);
    }
}
