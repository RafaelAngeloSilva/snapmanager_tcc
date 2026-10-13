<?php

namespace App\Http\Requests;

use App\Enums\ClientHistoryCategory;
use App\Models\Client;
use App\Support\FieldLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criar/editar uma anotação manual do Histórico.
 *
 * O cliente vem da ROTA, nunca do corpo: um client_id enviado é ignorado.
 * O ensaio relacionado precisa ser do mesmo cliente e não excluído.
 */
class ClientHistoryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Criar: o middleware de role da rota já decidiu. Editar: Policy no controller.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'notes' => is_string($this->notes) ? (trim($this->notes) === '' ? null : trim($this->notes)) : $this->notes,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'category' => ['required', 'string', Rule::enum(ClientHistoryCategory::class)],
            'title' => ['required', 'string', 'min:2', 'max:'.FieldLimits::HISTORY_TITLE, 'not_regex:'.FieldLimits::NO_HTML_PATTERN],
            'notes' => ['nullable', 'string', 'max:'.FieldLimits::HISTORY_NOTES, 'not_regex:'.FieldLimits::NO_HTML_PATTERN],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'appointment_id' => [
                'nullable', 'integer',
                Rule::exists('appointments', 'id')
                    ->where('client_id', $client->id)
                    ->whereNull('deleted_at'),
            ],
            'is_testimonial_candidate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Dados prontos para gravar. "Potencial depoimento" só existe em
     * registros de Pós-venda — em outra categoria é sempre false.
     *
     * @return array<string, mixed>
     */
    public function entryData(): array
    {
        $data = $this->safe()->only(['category', 'title', 'notes', 'event_date', 'appointment_id']);
        $data['notes'] ??= null;
        $data['appointment_id'] ??= null;
        $data['is_testimonial_candidate'] = $this->input('category') === ClientHistoryCategory::PostSale->value
            && $this->boolean('is_testimonial_candidate');

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Escolha uma categoria.',
            'category.enum' => 'Categoria inválida.',
            'title.required' => 'Informe um título.',
            'title.min' => 'O título precisa ter pelo menos 2 caracteres.',
            'title.max' => 'O título pode ter no máximo :max caracteres.',
            'notes.max' => 'A descrição pode ter no máximo :max caracteres.',
            '*.not_regex' => 'Não use marcações HTML (como <b> ou <script>) no texto.',
            'event_date.required' => 'Informe a data.',
            'event_date.date_format' => 'Data inválida.',
            'event_date.after_or_equal' => 'Data muito antiga.',
            'event_date.before_or_equal' => 'A data não pode passar de um ano à frente.',
            'appointment_id.exists' => 'Escolha um ensaio deste cliente.',
        ];
    }
}
