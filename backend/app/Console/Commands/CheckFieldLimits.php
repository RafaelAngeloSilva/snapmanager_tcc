<?php

namespace App\Console\Commands;

use App\Support\FieldLimits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Conta registros JÁ GRAVADOS que estão fora das regras de validação atuais
 * (texto maior que o limite, telefone/UF em formato inválido). Esses
 * registros continuam no banco normalmente, mas o formulário de edição
 * reenviaria o valor antigo e receberia 422 — este relatório mostra onde
 * corrigir antes que alguém tropece nisso.
 *
 * Só leitura. Mostra contagens e ids, nunca o conteúdo dos campos.
 */
class CheckFieldLimits extends Command
{
    protected $signature = 'data:check-limits {--ids : Lista os ids dos registros afetados}';

    protected $description = 'Relata registros existentes que excedem os limites de validação atuais (somente leitura)';

    /** @var array<string, array<string, int>> tabela => [coluna => limite] */
    private const LENGTHS = [
        'users' => ['name' => FieldLimits::PERSON_NAME],
        'clients' => [
            'name' => FieldLimits::PERSON_NAME, 'email' => FieldLimits::EMAIL, 'phone' => FieldLimits::PHONE,
            'street' => FieldLimits::STREET, 'number' => FieldLimits::ADDRESS_NUMBER, 'complement' => FieldLimits::COMPLEMENT,
            'neighborhood' => FieldLimits::NEIGHBORHOOD, 'city' => FieldLimits::CITY, 'notes' => FieldLimits::NOTES,
        ],
        'appointments' => ['title' => FieldLimits::TITLE, 'session_type' => FieldLimits::CATEGORY, 'location' => FieldLimits::LOCATION, 'notes' => FieldLimits::NOTES],
        'orders' => ['title' => FieldLimits::TITLE, 'service_type' => FieldLimits::CATEGORY, 'description' => FieldLimits::DESCRIPTION, 'notes' => FieldLimits::NOTES],
        'galleries' => ['title' => FieldLimits::TITLE, 'description' => FieldLimits::DESCRIPTION, 'watermark_text' => FieldLimits::WATERMARK_TEXT],
        'kanban_tasks' => ['title' => FieldLimits::TITLE, 'description' => FieldLimits::DESCRIPTION],
        'documents' => ['title' => FieldLimits::TITLE, 'notes' => FieldLimits::DOCUMENT_NOTES],
        'document_signers' => ['name' => FieldLimits::PERSON_NAME, 'email' => FieldLimits::EMAIL],
    ];

    public function handle(): int
    {
        $rows = [];
        // Nomes de coluna vêm da constante acima (nunca do usuário).
        $length = DB::getDriverName() === 'sqlite' ? 'length' : 'char_length';

        foreach (self::LENGTHS as $table => $columns) {
            foreach ($columns as $column => $limit) {
                $ids = DB::table($table)->whereRaw($length.'('.$column.') > ?', [$limit])->pluck('id')->all();
                $rows[] = [$table, $column, "> {$limit} caracteres", count($ids), $this->option('ids') ? implode(', ', $ids) : ''];
            }
        }

        // Formatos (verificados em PHP: a regex é a mesma da validação).
        $badPhones = DB::table('clients')->whereNotNull('phone')->where('phone', '!=', '')->get(['id', 'phone'])
            ->reject(fn ($row) => preg_match(FieldLimits::PHONE_PATTERN, $row->phone))->pluck('id')->all();
        $rows[] = ['clients', 'phone', 'formato inválido', count($badPhones), $this->option('ids') ? implode(', ', $badPhones) : ''];

        $badStates = DB::table('clients')->whereNotNull('state')->where('state', '!=', '')->get(['id', 'state'])
            ->reject(fn ($row) => in_array(mb_strtoupper($row->state), FieldLimits::STATES, true))->pluck('id')->all();
        $rows[] = ['clients', 'state', 'UF inválida', count($badStates), $this->option('ids') ? implode(', ', $badStates) : ''];

        $affected = array_values(array_filter($rows, fn ($row) => $row[3] > 0));

        if ($affected === []) {
            $this->info('Nenhum registro fora dos limites atuais.');

            return self::SUCCESS;
        }

        $this->warn('Registros que receberão erro de validação se forem editados sem corrigir o campo:');
        $this->table(['Tabela', 'Campo', 'Problema', 'Registros', 'Ids'], $affected);

        return self::SUCCESS;
    }
}
