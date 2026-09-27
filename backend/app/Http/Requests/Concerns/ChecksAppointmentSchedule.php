<?php

namespace App\Http\Requests\Concerns;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Validation\Validator;

trait ChecksAppointmentSchedule
{
    protected function currentAppointment(): ?Appointment
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment ? $appointment : null;
    }

    protected function effectiveUserId(): ?int
    {
        return $this->has('user_id')
            ? $this->input('user_id')
            : $this->currentAppointment()?->user_id;
    }

    protected function effectiveStatus(): string
    {
        // ->status (quando vem do model, não do input bruto da requisição) já
        // é um AppointmentStatus (cast do Eloquent) — precisa de ->value pra
        // não misturar enum com string na comparação feita mais abaixo.
        return $this->input('status', $this->currentAppointment()?->status?->value ?? 'scheduled');
    }

    protected function effectiveStartsAt(): mixed
    {
        return $this->input('starts_at', $this->currentAppointment()?->starts_at);
    }

    protected function effectiveEndsAt(): mixed
    {
        return $this->input('ends_at', $this->currentAppointment()?->ends_at);
    }

    /**
     * Validate that ends_at is after starts_at and that there is no schedule
     * conflict for the same user_id, considering the effective (merged) values
     * so partial updates are checked against the appointment's current data too.
     */
    protected function validateAppointmentSchedule(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['client_id', 'user_id', 'starts_at', 'ends_at', 'status'])) {
            return;
        }

        // Só valida "não pode ser no passado" quando starts_at foi realmente
        // enviado E é diferente do valor já salvo — o formulário reenvia o
        // starts_at atual em toda edição, e um agendamento que já começou ou
        // terminou (status "completed", por exemplo) precisa continuar
        // editável nos outros campos sem ser forçado a mudar de data.
        // Tolerância de 1 minuto pro tempo entre o usuário escolher o
        // horário no picker e a requisição chegar no servidor.
        if ($this->filled('starts_at')) {
            $submittedStartsAt = Carbon::parse($this->input('starts_at'));
            $currentStartsAt = $this->currentAppointment()?->starts_at;
            $isUnchanged = $currentStartsAt && $submittedStartsAt->equalTo($currentStartsAt);

            if (! $isUnchanged && $submittedStartsAt->lessThan(now()->subMinute())) {
                $validator->errors()->add('starts_at', 'A data do agendamento não pode ser anterior à data atual.');

                return;
            }
        }

        $startsAt = Carbon::parse($this->effectiveStartsAt());
        $endsAt = Carbon::parse($this->effectiveEndsAt());

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $validator->errors()->add('ends_at', 'O horário de término deve ser posterior ao horário de início.');

            return;
        }

        $userId = $this->effectiveUserId();
        $status = $this->effectiveStatus();

        if (! $userId || $status === 'cancelled') {
            return;
        }

        $conflict = Appointment::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($this->currentAppointment(), fn ($query, $appointment) => $query->whereKeyNot($appointment->id))
            ->exists();

        if ($conflict) {
            $validator->errors()->add('starts_at', 'Já existe um agendamento para esse profissional nesse horário.');
        }
    }
}
