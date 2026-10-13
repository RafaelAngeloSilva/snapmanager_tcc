<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\LoyaltyTier;
use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientHistoryEntry;
use App\Models\Document;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Monta o Histórico do cliente: resumo, selo de fidelidade e linha do tempo.
 *
 * Os eventos AUTOMÁTICOS são derivados das tabelas de origem a cada
 * consulta (nada é copiado para outra tabela). Por isso eles refletem
 * sempre o estado atual: registros excluídos (soft delete) somem da linha
 * do tempo, e um ensaio cancelado aparece como cancelado.
 *
 * Grupos (filtros do front-end): shoots, financial, service, post_sale,
 * contracts.
 */
class ClientHistoryService
{
    /** Teto de eventos devolvidos — o histórico de um cliente é pequeno; isto só evita respostas gigantes. */
    public const MAX_EVENTS = 500;

    /**
     * Ensaios que contam como REALIZADOS: status concluído, não excluídos e
     * com data já passada (um ensaio marcado como concluído antes da hora
     * não conta até acontecer).
     *
     * @return Builder<Appointment>
     */
    public function completedShoots(Client $client): Builder
    {
        return Appointment::query()
            ->where('client_id', $client->id)
            ->where('status', AppointmentStatus::Completed->value)
            ->where('starts_at', '<=', now());
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Client $client): array
    {
        $completed = $this->completedShoots($client)->count();
        $tier = LoyaltyTier::fromCompletedShoots($completed);

        $lastShoot = $this->completedShoots($client)->max('starts_at');

        $nextShoot = Appointment::query()
            ->where('client_id', $client->id)
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, Client::PENDING_APPOINTMENT_STATUSES))
            ->where('starts_at', '>', now())
            ->min('starts_at');

        return [
            'client_since' => $client->created_at?->toIso8601String(),
            'completed_shoots' => $completed,
            'loyalty' => [
                'tier' => $tier->value,
                'label' => $tier->label(),
                'loyal_from' => LoyaltyTier::LOYAL_FROM,
                'vip_from' => LoyaltyTier::VIP_FROM,
            ],
            'last_shoot_at' => $this->iso($lastShoot),
            'next_shoot_at' => $this->iso($nextShoot),
        ];
    }

    /**
     * Ensaios do cliente para o campo "Ensaio relacionado" das anotações
     * (só os não excluídos — os mesmos que a validação aceita).
     *
     * @return list<array<string, mixed>>
     */
    public function shootOptions(Client $client): array
    {
        return Appointment::query()
            ->where('client_id', $client->id)
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get(['id', 'title', 'session_type', 'starts_at', 'status'])
            ->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'title' => $this->shootName($appointment),
                'starts_at' => $appointment->starts_at?->toIso8601String(),
                'status' => $appointment->status->value,
            ])
            ->all();
    }

    /**
     * Linha do tempo completa (automáticos + manuais), do mais recente para
     * o mais antigo. Ordenação e filtros finais ficam no front-end.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(Client $client, User $viewer): array
    {
        $events = [
            $this->event('client', $client->id, 'client_registered', 'service', $client->created_at, 'Cliente cadastrado', 'Cliente cadastrado no SnapManager.'),
            ...$this->appointmentEvents($client),
            ...$this->orderEvents($client),
            ...$this->documentEvents($client, $viewer),
            ...$this->galleryEvents($client),
            ...$this->manualEvents($client, $viewer),
        ];

        $events = array_values(array_filter($events, fn (?array $event) => $event !== null && $event['date'] !== null));

        // Mais recente primeiro; empate → ordem estável pela chave.
        usort($events, fn (array $a, array $b) => [$b['date'], $b['key']] <=> [$a['date'], $a['key']]);

        return array_slice($events, 0, self::MAX_EVENTS);
    }

    /**
     * @return list<array<string, mixed>|null>
     */
    private function appointmentEvents(Client $client): array
    {
        $events = [];
        $now = now();

        $appointments = Appointment::query()
            ->where('client_id', $client->id)
            ->get(['id', 'title', 'session_type', 'starts_at', 'status', 'created_at', 'updated_at']);

        foreach ($appointments as $appointment) {
            $name = $this->shootName($appointment);
            // O próprio evento já é sobre este ensaio (o nome está na
            // descrição) — não repete "Ensaio relacionado".
            $related = null;

            $events[] = $this->event('appointment', $appointment->id, 'shoot_scheduled', 'shoots', $appointment->created_at, 'Ensaio agendado', "{$name} foi agendado.", $related, ['scheduled_for' => $this->iso($appointment->starts_at)]);

            if ($appointment->status === AppointmentStatus::Completed && $appointment->starts_at <= $now) {
                $events[] = $this->event('appointment', $appointment->id, 'shoot_completed', 'shoots', $appointment->starts_at, 'Ensaio realizado', "{$name} concluído.", $related);
            }

            // Não há coluna de "data do cancelamento": usa a última alteração
            // do agendamento (o cancelamento costuma ser a última mudança).
            if ($appointment->status === AppointmentStatus::Cancelled) {
                $events[] = $this->event('appointment', $appointment->id, 'shoot_cancelled', 'shoots', $appointment->updated_at, 'Ensaio cancelado', "{$name} foi cancelado.", $related, ['scheduled_for' => $this->iso($appointment->starts_at)]);
            }
        }

        return $events;
    }

    /**
     * Pedidos: o valor total é o único dado financeiro real do sistema (não
     * há registro de pagamentos), então o evento é "Pedido registrado", não
     * "Pagamento recebido".
     *
     * @return list<array<string, mixed>|null>
     */
    private function orderEvents(Client $client): array
    {
        $events = [];

        $orders = Order::query()
            ->where('client_id', $client->id)
            ->with([
                'appointment:id,title,session_type,client_id',
                'statusHistories' => fn ($query) => $query->where('to_status', OrderStatus::Delivered->value)->latest('created_at'),
            ])
            ->get(['id', 'client_id', 'appointment_id', 'title', 'total', 'status', 'created_at']);

        foreach ($orders as $order) {
            $related = $order->appointment ? ['id' => $order->appointment->id, 'title' => $this->shootName($order->appointment)] : null;
            $total = (float) $order->total;

            $events[] = $this->event(
                'order', $order->id, 'order_created', 'financial', $order->created_at,
                'Pedido registrado',
                $total > 0 ? "{$order->title} — {$this->money($total)}." : "{$order->title}.",
                $related,
                ['amount' => $total > 0 ? number_format($total, 2, '.', '') : null],
            );

            $delivered = $order->statusHistories->first();

            if ($order->status === OrderStatus::Delivered && $delivered) {
                $events[] = $this->event('order', $order->id, 'order_delivered', 'post_sale', $delivered->created_at, 'Pedido entregue', "{$order->title} foi entregue ao cliente.", $related);
            }
        }

        return $events;
    }

    /**
     * Contratos/documentos: respeita a mesma regra de visibilidade da tela
     * de Documentos (Document::visibleTo).
     *
     * @return list<array<string, mixed>|null>
     */
    private function documentEvents(Client $client, User $viewer): array
    {
        $events = [];

        $documents = Document::query()
            ->where('client_id', $client->id)
            ->visibleTo($viewer)
            ->with('appointment:id,title,session_type')
            ->get(['id', 'client_id', 'appointment_id', 'order_id', 'title', 'type', 'created_at', 'sent_at', 'signed_at']);

        foreach ($documents as $document) {
            $related = $document->appointment ? ['id' => $document->appointment->id, 'title' => $this->shootName($document->appointment)] : null;

            $events[] = $this->event('document', $document->id, 'contract_created', 'contracts', $document->created_at, 'Contrato criado', "{$document->title} ({$document->type->label()}).", $related);
            $events[] = $this->event('document', $document->id, 'contract_sent', 'contracts', $document->sent_at, 'Contrato enviado para assinatura', "{$document->title} foi enviado ao cliente.", $related);
            $events[] = $this->event('document', $document->id, 'contract_signed', 'contracts', $document->signed_at, 'Contrato assinado', "{$document->title} foi assinado.", $related);
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>|null>
     */
    private function galleryEvents(Client $client): array
    {
        $events = [];

        $galleries = Gallery::query()
            ->where('client_id', $client->id)
            ->get(['id', 'client_id', 'title', 'created_at', 'selection_completed_at']);

        foreach ($galleries as $gallery) {
            $events[] = $this->event('gallery', $gallery->id, 'gallery_created', 'post_sale', $gallery->created_at, 'Galeria criada', "Galeria \"{$gallery->title}\" disponível para o cliente.");
            $events[] = $this->event('gallery', $gallery->id, 'gallery_selection_completed', 'post_sale', $gallery->selection_completed_at, 'Seleção de fotos concluída', "O cliente concluiu a escolha das fotos em \"{$gallery->title}\".");
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manualEvents(Client $client, User $viewer): array
    {
        return $client->historyEntries()
            ->with(['author:id,name', 'appointment:id,title,session_type,client_id'])
            ->get()
            ->map(fn (ClientHistoryEntry $entry) => $this->manualEvent($entry, $viewer))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function manualEvent(ClientHistoryEntry $entry, User $viewer): array
    {
        // Ensaio relacionado só aparece se ainda existir e for do cliente.
        $appointment = $entry->appointment && $entry->appointment->client_id === $entry->client_id ? $entry->appointment : null;

        return [
            ...$this->event(
                'entry', $entry->id, 'manual', $entry->category->group(),
                // A anotação tem só DIA (sem hora). Para ordenar junto com os
                // eventos automáticos usa o meio-dia UTC desse dia; a tela
                // exibe entry.event_date, sem conversão de fuso.
                $entry->event_date->copy()->setTime(12, 0),
                $entry->title,
                $entry->notes,
                $appointment ? ['id' => $appointment->id, 'title' => $this->shootName($appointment)] : null,
            ),
            'source' => 'manual',
            'entry' => [
                'id' => $entry->id,
                'category' => $entry->category->value,
                'category_label' => $entry->category->label(),
                'event_date' => $entry->event_date->toDateString(),
                'appointment_id' => $appointment?->id,
                'is_testimonial_candidate' => $entry->is_testimonial_candidate,
                'author' => $entry->author?->name,
                'can_edit' => $viewer->can('update', $entry),
            ],
        ];
    }

    /**
     * @param  array{id:int,title:string}|null  $related
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function event(string $source, int $id, string $type, string $group, ?CarbonInterface $date, string $title, ?string $description, ?array $related = null, array $meta = []): ?array
    {
        if (! $date) {
            return null;
        }

        return [
            // id com zeros à esquerda: o desempate da ordenação é por texto.
            'key' => sprintf('%s-%010d-%s', $source, $id, $type),
            'source' => 'auto',
            'type' => $type,
            'group' => $group,
            'date' => $date->toIso8601String(),
            'title' => $title,
            'description' => $description,
            'appointment' => $related,
            'meta' => $meta ?: null,
        ];
    }

    public function shootName(Appointment $appointment): string
    {
        $name = trim((string) ($appointment->title ?: $appointment->session_type));

        return match (true) {
            $name === '' => 'Ensaio',
            // "Ensaio Família" já diz o que é — não vira "Ensaio Ensaio Família".
            str_starts_with(mb_strtolower($name), 'ensaio') => $name,
            default => "Ensaio {$name}",
        };
    }

    private function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof CarbonInterface ? $value : Carbon::parse($value))->toIso8601String();
    }
}
