<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DocumentFileType;
use App\Enums\DocumentType;
use App\Support\DocxInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Campos do documento compartilhados entre criação e edição.
 *
 * Pedido e agendamento só podem ser vinculados se pertencerem ao MESMO
 * cliente do documento — não dá para anexar o contrato do cliente A ao
 * pedido do cliente B mandando ids arbitrários.
 */
trait ValidatesDocumentFields
{
    /** Tamanho máximo do PDF, em KB (10 MB). */
    public const MAX_FILE_KB = 10240;

    /** A Clicksign aceita prazo de até 90 dias após o upload. */
    public const MAX_DEADLINE_DAYS = 90;

    /**
     * @return array<string, mixed>
     */
    protected function documentRules(int $clientId, bool $fileRequired): array
    {
        return [
            'title' => [$fileRequired ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$fileRequired ? 'required' : 'sometimes', Rule::enum(DocumentType::class)],
            'client_id' => [
                $fileRequired ? 'required' : 'sometimes',
                'integer',
                Rule::exists('clients', 'id')->whereNull('deleted_at'),
            ],
            'order_id' => [
                'nullable',
                'integer',
                Rule::exists('orders', 'id')->where('client_id', $clientId)->whereNull('deleted_at'),
            ],
            'appointment_id' => [
                'nullable',
                'integer',
                Rule::exists('appointments', 'id')->where('client_id', $clientId)->whereNull('deleted_at'),
            ],
            'expires_at' => ['nullable', 'date', 'after:today', 'before_or_equal:'.now()->addDays(self::MAX_DEADLINE_DAYS)->toDateString()],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Só PDF e DOCX, em três camadas:
            //  - `extensions`: a extensão do NOME enviado (.pdf/.docx) —
            //    é ela que decide o formato (fileTypeOf);
            //  - `mimes`/`mimetypes`: o tipo detectado pelo CONTEÚDO
            //    (fileinfo). Dependendo da versão do libmagic, um .docx é
            //    detectado como ZIP genérico — por isso "zip" entra aqui;
            //  - validateFileContents(): a estrutura real precisa bater com a
            //    extensão (assinatura %PDF- ou Office Open XML válido).
            'file' => [
                $fileRequired ? 'required' : 'sometimes',
                'file',
                'extensions:pdf,docx',
                'mimes:pdf,docx,zip',
                'mimetypes:application/pdf,'.DocumentFileType::Docx->mimeType().',application/zip',
                'max:'.self::MAX_FILE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function documentMessages(): array
    {
        return [
            'order_id.exists' => 'O pedido selecionado não pertence a este cliente.',
            'appointment_id.exists' => 'O agendamento selecionado não pertence a este cliente.',
            'expires_at.after' => 'O prazo precisa ser uma data futura.',
            'expires_at.before_or_equal' => 'O prazo pode ser de no máximo '.self::MAX_DEADLINE_DAYS.' dias (limite da Clicksign).',
            'file.required' => 'Envie o contrato em PDF ou Word (.docx).',
            'file.mimetypes' => 'O arquivo precisa ser um PDF ou um documento Word (.docx).',
            'file.extensions' => 'Formato não aceito. Envie um PDF ou um documento Word (.docx).',
            'file.mimes' => 'Formato não aceito. Envie um PDF ou um documento Word (.docx).',
            'file.max' => 'O arquivo pode ter no máximo 10 MB.',
        ];
    }

    /**
     * O formato do arquivo, decidido pela EXTENSÃO já validada (pdf/docx).
     * O conteúdo precisa bater com ela — ver validateFileContents().
     */
    public static function fileTypeOf(UploadedFile $file): DocumentFileType
    {
        return strtolower($file->getClientOriginalExtension()) === 'docx' ? DocumentFileType::Docx : DocumentFileType::Pdf;
    }

    /**
     * Terceira camada, pelo conteúdo real:
     *  - PDF: os primeiros bytes precisam ser a assinatura "%PDF-";
     *  - DOCX: estrutura Office Open XML válida e segura (DocxInspector) —
     *    recusa ZIP qualquer, .docm renomeado, macros, zip bomb e vínculos
     *    externos.
     * Um PDF renomeado para .docx (ou o contrário) é recusado.
     */
    protected function validateFileContents(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $file = $this->file('file');

            if (! $file instanceof UploadedFile || $validator->errors()->has('file')) {
                return;
            }

            if (self::fileTypeOf($file) === DocumentFileType::Docx) {
                if ($problem = DocxInspector::problem($file->getRealPath())) {
                    $validator->errors()->add('file', $problem);
                }

                return;
            }

            $handle = @fopen($file->getRealPath(), 'rb');
            $header = $handle ? fread($handle, 5) : '';

            if ($handle) {
                fclose($handle);
            }

            if ($header !== '%PDF-') {
                $validator->errors()->add('file', 'O arquivo não é um PDF válido.');
            }
        });
    }
}
