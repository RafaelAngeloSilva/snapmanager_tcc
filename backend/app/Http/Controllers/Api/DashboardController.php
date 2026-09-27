<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\GalleryStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardAlertResource;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Gallery;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    /**
     * Antes: 14 queries nesse endpoint (uma COUNT/SUM por indicador —
     * medido com DB::enableQueryLog() antes desta otimização). Cada grupo
     * abaixo (agendamentos, pedidos, galerias) agora sai em UMA query só,
     * usando "COUNT(*) FILTER (WHERE ...)" do PostgreSQL pra somar vários
     * contadores condicionais numa única passada pela tabela — resultado
     * final idêntico ao de antes, só que em ~4 queries em vez de 14.
     */
    public function summary(): JsonResponse
    {
        $today = Carbon::today();

        $appointmentStats = Appointment::query()
            ->selectRaw('count(*) as total_appointments')
            // date(...) em vez de "starts_at::date" (cast do Postgres) —
            // date() é uma função padrão que também funciona no SQLite,
            // usado pela suíte de testes (ver phpunit.xml).
            ->selectRaw('count(*) filter (where date(starts_at) = ?) as appointments_today', [$today->toDateString()])
            ->selectRaw(
                'count(*) filter (where starts_at > ? and status != ?) as upcoming_appointments',
                [now(), AppointmentStatus::Cancelled->value]
            )
            ->selectRaw('count(*) filter (where status = ?) as cancelled_appointments', [AppointmentStatus::Cancelled->value])
            ->first();

        $orderStatsQuery = Order::query()->selectRaw('count(*) as total_orders');

        foreach (OrderStatus::cases() as $status) {
            $orderStatsQuery->selectRaw("count(*) filter (where status = ?) as {$status->value}_orders", [$status->value]);
        }

        $orderStats = $orderStatsQuery
            ->selectRaw('count(*) filter (where due_date < ? and status not in (?, ?)) as overdue_orders', [
                $today->toDateString(), OrderStatus::Delivered->value, OrderStatus::Cancelled->value,
            ])
            ->selectRaw('coalesce(sum(total) filter (where status = ?), 0) as revenue_delivered', [OrderStatus::Delivered->value])
            ->selectRaw('coalesce(sum(total) filter (where status not in (?, ?)), 0) as revenue_pending', [
                OrderStatus::Delivered->value, OrderStatus::Cancelled->value,
            ])
            ->first();

        $ordersByStatus = collect(OrderStatus::cases())
            ->mapWithKeys(fn ($status) => ["{$status->value}_orders" => (int) $orderStats->{"{$status->value}_orders"}])
            ->all();

        return response()->json([
            'clients' => [
                'total_clients' => Client::count(),
            ],
            'appointments' => [
                'total_appointments' => (int) $appointmentStats->total_appointments,
                'appointments_today' => (int) $appointmentStats->appointments_today,
                'upcoming_appointments' => (int) $appointmentStats->upcoming_appointments,
                'cancelled_appointments' => (int) $appointmentStats->cancelled_appointments,
            ],
            'orders' => array_merge(
                ['total_orders' => (int) $orderStats->total_orders],
                $ordersByStatus,
                ['overdue_orders' => (int) $orderStats->overdue_orders]
            ),
            'financial' => [
                'total_revenue_delivered' => (float) $orderStats->revenue_delivered,
                'total_revenue_pending' => (float) $orderStats->revenue_pending,
            ],
            'galleries' => $this->gallerySummary(),
        ]);
    }

    /**
     * Também consolidado numa query só (era status groupBy + count + 2
     * whereNull/whereNotNull count = 4 queries antes).
     *
     * @return array<string, int>
     */
    private function gallerySummary(): array
    {
        $stats = Gallery::query()
            ->selectRaw('count(*) as total_galleries')
            ->selectRaw('count(*) filter (where status = ?) as active_galleries', [GalleryStatus::Active->value])
            ->selectRaw('count(*) filter (where status = ?) as expired_galleries', [GalleryStatus::Expired->value])
            ->selectRaw('count(*) filter (where status = ?) as closed_galleries', [GalleryStatus::Closed->value])
            ->selectRaw('count(*) filter (where selection_completed_at is null) as awaiting_selection_galleries')
            ->selectRaw('count(*) filter (where selection_completed_at is not null) as completed_selection_galleries')
            ->first();

        return [
            'total_galleries' => (int) $stats->total_galleries,
            'active_galleries' => (int) $stats->active_galleries,
            'expired_galleries' => (int) $stats->expired_galleries,
            'closed_galleries' => (int) $stats->closed_galleries,
            'awaiting_selection_galleries' => (int) $stats->awaiting_selection_galleries,
            'completed_selection_galleries' => (int) $stats->completed_selection_galleries,
        ];
    }

    /**
     * Dados agregados para os gráficos do Dashboard — o React só desenha,
     * todo o GROUP BY/COUNT/SUM acontece aqui no banco.
     */
    public function charts(): JsonResponse
    {
        return response()->json([
            'orders_by_status' => $this->ordersByStatusChartData(),
            'revenue_by_period' => $this->revenueByPeriodChartData(),
            'appointments_by_period' => $this->appointmentsByPeriodChartData(),
        ]);
    }

    /**
     * @return list<array{status: string, label: string, count: int}>
     */
    private function ordersByStatusChartData(): array
    {
        $counts = Order::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return array_map(
            fn (OrderStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ],
            OrderStatus::cases()
        );
    }

    /**
     * Faturamento dos últimos 30 dias, só pedidos entregues. Não há uma
     * coluna dedicada de "data de entrega" — created_at é usado como proxy
     * (mesma decisão já documentada no relatório em PDF do Dashboard).
     *
     * @return list<array{date: string, total: float}>
     */
    private function revenueByPeriodChartData(): array
    {
        $days = 30;
        $start = Carbon::today()->subDays($days - 1);

        $rows = Order::query()
            ->where('status', OrderStatus::Delivered->value)
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, SUM(total) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];
        for ($date = $start->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'total' => (float) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Agendamentos por dia nos próximos 7 dias (hoje incluso), sem contar
     * os cancelados.
     *
     * @return list<array{date: string, count: int}>
     */
    private function appointmentsByPeriodChartData(): array
    {
        $start = Carbon::today();
        $end = $start->copy()->addDays(6);

        $rows = Appointment::query()
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->whereBetween('starts_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('DATE(starts_at) as day, count(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $series = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }

    public function alerts(Request $request): AnonymousResourceCollection
    {
        $alerts = $this->buildAlerts()
            ->when($request->filled('type'), fn ($collection) => $collection->where('type', $request->input('type')))
            ->when($request->filled('severity'), fn ($collection) => $collection->where('severity', $request->input('severity')))
            ->values();

        return DashboardAlertResource::collection($alerts);
    }

    /**
     * Relatório operacional em PDF, com os mesmos indicadores do summary()/
     * alerts() (nada de recalcular do zero) — protegido por auth:sanctum,
     * sem rota pública equivalente.
     */
    public function report(Request $request): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;
        $today = Carbon::today();

        // Métricas de "estado atual" (atrasados, prontos p/ entrega, status de
        // galeria) refletem a operação agora — não fazem sentido recortadas
        // por período. Só o volume (agendamentos/pedidos criados, receita) é
        // filtrado pelo período informado, quando houver.
        $appointmentsQuery = Appointment::query()->where('status', '!=', 'cancelled');
        $ordersCreatedQuery = Order::query();

        if ($startDate) {
            $appointmentsQuery->whereDate('starts_at', '>=', $startDate);
            $ordersCreatedQuery->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $appointmentsQuery->whereDate('starts_at', '<=', $endDate);
            $ordersCreatedQuery->whereDate('created_at', '<=', $endDate);
        }

        $orderStatusCounts = (clone $ordersCreatedQuery)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $ordersByStatus = [];
        foreach (OrderStatus::cases() as $status) {
            $ordersByStatus[$status->value] = (int) ($orderStatusCounts[$status->value] ?? 0);
        }

        // "Estado atual" (não filtrado por período, ver comentário acima) —
        // consolidado numa query só via FILTER, em vez de 3 counts + 2 sums
        // separados.
        $currentOrderStats = Order::query()
            ->selectRaw('count(*) filter (where due_date < ? and status not in (?, ?)) as overdue', [
                $today->toDateString(), OrderStatus::Delivered->value, OrderStatus::Cancelled->value,
            ])
            ->selectRaw('count(*) filter (where due_date between ? and ? and status not in (?, ?)) as due_soon', [
                $today->toDateString(), $today->copy()->addDays(7)->toDateString(),
                OrderStatus::Delivered->value, OrderStatus::Cancelled->value,
            ])
            ->selectRaw('count(*) filter (where status = ?) as ready_for_delivery', [OrderStatus::ReadyForDelivery->value])
            ->selectRaw('coalesce(sum(total) filter (where status = ?), 0) as revenue_delivered', [OrderStatus::Delivered->value])
            ->selectRaw('coalesce(sum(total) filter (where status not in (?, ?)), 0) as revenue_pending', [
                OrderStatus::Delivered->value, OrderStatus::Cancelled->value,
            ])
            ->first();

        $data = [
            'issued_at' => now(),
            'issued_by' => $request->user()?->name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'clients' => [
                'total' => Client::count(),
            ],
            'appointments' => [
                'today' => Appointment::whereBetween('starts_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->count(),
                'in_period' => (clone $appointmentsQuery)->count(),
                'upcoming' => Appointment::where('starts_at', '>', now())->where('status', '!=', 'cancelled')->count(),
            ],
            'orders' => [
                'total_in_period' => array_sum($ordersByStatus),
                'by_status' => $ordersByStatus,
                'overdue' => (int) $currentOrderStats->overdue,
                'due_soon' => (int) $currentOrderStats->due_soon,
                'ready_for_delivery' => (int) $currentOrderStats->ready_for_delivery,
            ],
            'financial' => [
                'revenue_delivered' => (float) $currentOrderStats->revenue_delivered,
                'revenue_pending' => (float) $currentOrderStats->revenue_pending,
            ],
            'galleries' => $this->gallerySummary(),
            'alerts' => $this->buildAlerts()->take(5)->values()->all(),
        ];

        $pdf = Pdf::loadView('reports.dashboard', $data)->setPaper('a4');

        return $pdf->download('relatorio-operacional-snapmanager-'.now()->format('Y-m-d').'.pdf');
    }

    private function buildAlerts(): Collection
    {
        $today = Carbon::today();
        $soonLimit = $today->copy()->addDays(7);

        $alerts = collect();

        Order::query()
            ->where('due_date', '<', $today->toDateString())
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->get()
            ->each(function (Order $order) use ($alerts) {
                $alerts->push([
                    'type' => 'overdue_order',
                    'severity' => 'high',
                    'title' => 'Pedido atrasado',
                    'message' => "O pedido {$order->title} está atrasado desde {$order->due_date->format('Y-m-d')}.",
                    'entity_type' => 'order',
                    'entity_id' => $order->id,
                    'date' => $order->due_date->format('Y-m-d'),
                ]);
            });

        Order::query()
            ->whereBetween('due_date', [$today->toDateString(), $soonLimit->toDateString()])
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->get()
            ->each(function (Order $order) use ($alerts) {
                $alerts->push([
                    'type' => 'due_soon_order',
                    'severity' => 'medium',
                    'title' => 'Pedido com prazo próximo',
                    'message' => "O pedido {$order->title} vence em {$order->due_date->format('Y-m-d')}.",
                    'entity_type' => 'order',
                    'entity_id' => $order->id,
                    'date' => $order->due_date->format('Y-m-d'),
                ]);
            });

        Appointment::query()
            ->with('client')
            // Intervalo em vez de whereDate(): "date(starts_at) = ?" impede o
            // PostgreSQL de usar o índice de starts_at.
            ->whereBetween('starts_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->where('status', '!=', 'cancelled')
            ->get()
            ->each(function (Appointment $appointment) use ($alerts) {
                // Cliente pode ter sido soft-deleted sem o agendamento ser
                // afetado; a relação resolve como null nesse caso.
                $clientName = $appointment->client->name ?? 'cliente removido';

                $alerts->push([
                    'type' => 'appointment_today',
                    'severity' => 'info',
                    'title' => 'Agendamento hoje',
                    'message' => "Agendamento \"{$appointment->title}\" hoje às {$appointment->starts_at->format('H:i')} com {$clientName}.",
                    'entity_type' => 'appointment',
                    'entity_id' => $appointment->id,
                    'date' => $appointment->starts_at->format('Y-m-d'),
                ]);
            });

        Order::query()
            ->where('status', 'ready_for_delivery')
            ->get()
            ->each(function (Order $order) use ($alerts) {
                $alerts->push([
                    'type' => 'ready_for_delivery',
                    'severity' => 'medium',
                    'title' => 'Pedido pronto para entrega',
                    'message' => "O pedido {$order->title} está pronto para entrega.",
                    'entity_type' => 'order',
                    'entity_id' => $order->id,
                    'date' => optional($order->due_date)->format('Y-m-d'),
                ]);
            });

        // Acompanhe seu Pedido: cliente abriu o link de acompanhamento nas
        // últimas 24h (visualizações da equipe via "Visualizar como cliente"
        // não contam — ver PublicOrderTrackingController).
        Order::query()
            ->with('client:id,name')
            ->whereNotNull('tracking_token')
            ->where('tracking_last_viewed_at', '>=', now()->subDay())
            ->orderByDesc('tracking_last_viewed_at')
            ->get()
            ->each(function (Order $order) use ($alerts) {
                $clientName = $order->client->name ?? 'O cliente';

                $alerts->push([
                    'type' => 'tracking_viewed',
                    'severity' => 'info',
                    'title' => 'Cliente acompanhou o pedido',
                    'message' => "{$clientName} abriu o acompanhamento do pedido {$order->title} às {$order->tracking_last_viewed_at->format('H:i')}.",
                    'entity_type' => 'order',
                    'entity_id' => $order->id,
                    'date' => $order->tracking_last_viewed_at->format('Y-m-d'),
                ]);
            });

        $severityOrder = ['high' => 0, 'medium' => 1, 'info' => 2];

        return $alerts->sortBy(fn ($alert) => $severityOrder[$alert['severity']] ?? 99)->values();
    }
}
