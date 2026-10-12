<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Filtro opcional por cliente (usado no formulário de pedido, que só
        // pode vincular agendamentos do próprio cliente). Validado antes da
        // query: texto/array numa coluna inteira viraria erro 500.
        $filters = $request->validate(['client_id' => ['nullable', 'integer', 'min:1']]);

        return AppointmentResource::collection(
            Appointment::with(['client', 'user'])
                ->when($filters['client_id'] ?? null, fn ($query, $clientId) => $query->where('client_id', $clientId))
                ->orderBy('starts_at')
                ->paginate(15)
                ->withQueryString()
        );
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $appointment = Appointment::create($request->validated());
        $appointment->load(['client', 'user']);

        return AppointmentResource::make($appointment)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        return AppointmentResource::make($appointment->load(['client', 'user']));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $appointment->update($request->validated());

        return AppointmentResource::make($appointment->load(['client', 'user']));
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $appointment->delete();

        return response()->json(null, 204);
    }
}
