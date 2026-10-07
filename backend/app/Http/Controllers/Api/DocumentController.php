<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentConversionStatus;
use App\Enums\DocumentEventType;
use App\Enums\DocumentFileType;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Exceptions\DocumentConversionException;
use App\Http\Requests\Concerns\ValidatesDocumentFields;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Models\Order;
use App\Models\User;
use App\Services\DocumentConversionService;
use App\Services\FileStorage;
use App\Support\FileKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /** Todos os arquivos (original, PDF para assinatura) passam por aqui: disco local ou S3. */
    public function __construct(private readonly FileStorage $files) {}


    /** Filtros da listagem → estados locais. */
    private const STATUS_GROUPS = [
        'draft' => [DocumentStatus::Draft],
        'awaiting' => [DocumentStatus::Preparing, DocumentStatus::AwaitingSignature, DocumentStatus::PartiallySigned],
        'signed' => [DocumentStatus::Signed],
        'canceled' => [DocumentStatus::Canceled, DocumentStatus::Declined, DocumentStatus::Expired],
        'error' => [DocumentStatus::IntegrationError],
    ];

    /**
     * Listagem paginada. Não carrega PDF, histórico nem contatos: só o
     * necessário para a tabela. Consultas fixas (sem N+1): documentos +
     * clientes + signatários (colunas mínimas).
     *
     * `order_id` / `appointment_id` alimentam a seção "Documentos
     * vinculados" das telas de Pedidos e Agenda. São filtros ADICIONAIS:
     * visibleTo() continua valendo antes deles, então ver um pedido ou
     * agendamento não dá acesso a documentos que o perfil não enxergaria na
     * própria aba Documentos.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);

        $filters = $request->validate([
            'order_id' => ['nullable', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
        ]);

        $documents = Document::query()
            ->visibleTo($request->user())
            ->when($filters['order_id'] ?? null, fn ($query, int $orderId) => $query->where('order_id', $orderId))
            ->when($filters['appointment_id'] ?? null, fn ($query, int $appointmentId) => $query->where('appointment_id', $appointmentId))
            ->with([
                'client:id,name',
                'signers:id,document_id,name,channel,status,signed_at',
            ])
            ->when(
                self::STATUS_GROUPS[$request->input('status')] ?? null,
                fn ($query, array $statuses) => $query->whereIn('status', array_map(fn ($s) => $s->value, $statuses))
            )
            ->when(trim((string) $request->input('search')), function ($query, string $search) {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(fn ($q) => $q
                    ->whereRaw('lower(title) like ?', [$term])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw('lower(name) like ?', [$term])));
            })
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return DocumentResource::collection($documents);
    }

    /**
     * Pedidos e agendamentos DO cliente escolhido, para os selects do
     * formulário. Fica aqui (e não como filtro novo na agenda) para não
     * alterar os módulos existentes. Mesma regra da validação: só registros
     * do próprio cliente.
     */
    public function linkOptions(Request $request): JsonResponse
    {
        Gate::authorize('create', Document::class);

        $clientId = (int) $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ])['client_id'];

        return response()->json([
            'orders' => Order::where('client_id', $clientId)->latest()->limit(50)->get(['id', 'title', 'due_date']),
            'appointments' => Appointment::where('client_id', $clientId)->orderByDesc('starts_at')->limit(50)->get(['id', 'title', 'starts_at']),
        ]);
    }

    /**
     * Novo documento (rascunho). PDF: fluxo de sempre. DOCX: o Word é
     * guardado como ORIGINAL e uma cópia é convertida para o PDF que será
     * assinado. Se a conversão falhar, o rascunho fica salvo com a falha
     * registrada — dá para tentar de novo (retryConversion) sem reenviar o
     * arquivo. Nada vai para a Clicksign aqui.
     */
    public function store(StoreDocumentRequest $request, DocumentConversionService $converter): JsonResponse
    {
        Gate::authorize('create', Document::class);

        $data = $request->validated();
        $upload = $request->file('file');
        $fileType = ValidatesDocumentFields::fileTypeOf($upload);
        $path = $this->storeFile($upload, $fileType);

        try {
            $document = DB::transaction(function () use ($data, $request, $upload, $fileType, $path) {
                $document = new Document([
                    ...collect($data)->only(['client_id', 'order_id', 'appointment_id', 'title', 'type', 'notes', 'expires_at'])->all(),
                    'original_file_path' => $path,
                    'original_file_name' => $this->safeOriginalName($upload, $fileType),
                    'original_file_size' => $upload->getSize(),
                    'original_file_type' => $fileType,
                    'created_by' => $request->user()->id,
                ]);
                $document->status = DocumentStatus::Draft;
                $document->save();

                foreach ($data['signers'] ?? [] as $signer) {
                    $this->createSigner($document, $signer);
                }

                $document->recordEvent(DocumentEventType::Created, 'Documento criado como rascunho.', user: $request->user());

                return $document;
            });
        } catch (\Throwable $e) {
            // Sem registro no banco, o arquivo ficaria órfão no disco.
            $this->files->delete($path);

            throw $e;
        }

        $warning = $fileType->needsConversion() ? $this->convert($document, $request->user(), $converter) : null;

        return DocumentResource::make($this->loadDetail($document))
            ->additional(['message' => $warning])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return DocumentResource::make($this->loadDetail($document));
    }

    public function update(UpdateDocumentRequest $request, Document $document, DocumentConversionService $converter): DocumentResource|JsonResponse
    {
        Gate::authorize('update', $document);

        if (! $document->status->isEditable()) {
            return response()->json(['message' => 'Só é possível editar documentos em rascunho.'], 409);
        }

        $data = $request->validated();
        $upload = $request->file('file');
        $newType = $upload ? ValidatesDocumentFields::fileTypeOf($upload) : null;
        $newPath = $upload ? $this->storeFile($upload, $newType) : null;
        // Arquivos que deixam de valer se o contrato for trocado: o original
        // e o PDF convertido do Word anterior (se houver).
        $oldFiles = array_values(array_filter([$document->original_file_path, $document->signing_file_path]));

        DB::transaction(function () use ($document, $data, $request, $upload, $newType, $newPath) {
            $document->fill(collect($data)->only(['title', 'type', 'client_id', 'order_id', 'appointment_id', 'notes', 'expires_at'])->all());

            // Trocou o cliente sem informar pedido/agendamento: vínculos
            // antigos que não sejam do novo cliente são desfeitos.
            if ($document->isDirty('client_id')) {
                if (! array_key_exists('order_id', $data) && $document->order_id
                    && Order::whereKey($document->order_id)->value('client_id') !== $document->client_id) {
                    $document->order_id = null;
                }

                if (! array_key_exists('appointment_id', $data) && $document->appointment_id
                    && Appointment::whereKey($document->appointment_id)->value('client_id') !== $document->client_id) {
                    $document->appointment_id = null;
                }
            }

            $changed = array_keys($document->getDirty());

            if ($newPath) {
                $document->fill([
                    'original_file_path' => $newPath,
                    'original_file_name' => $this->safeOriginalName($upload, $newType),
                    'original_file_size' => $upload->getSize(),
                    'original_file_type' => $newType,
                ]);
                // O PDF para assinatura precisa ser refeito a partir do novo arquivo.
                $document->forceFill(['signing_file_path' => null, 'signing_file_size' => null, 'conversion_status' => null]);
            }

            $document->save();

            if ($changed !== []) {
                $document->recordEvent(DocumentEventType::Updated, 'Campos alterados: '.implode(', ', $changed).'.', user: $request->user());
            }

            if ($newPath) {
                $document->recordEvent(DocumentEventType::FileReplaced, "O arquivo do contrato foi substituído ({$newType->label()}).", user: $request->user());
            }
        });

        $warning = null;

        if ($newPath) {
            $this->files->delete($oldFiles);
            $warning = $newType->needsConversion() ? $this->convert($document, $request->user(), $converter) : null;
        }

        return DocumentResource::make($this->loadDetail($document))->additional(['message' => $warning]);
    }

    /**
     * Tenta de novo a conversão de um rascunho em Word cuja conversão falhou
     * (ex.: o LibreOffice ainda não estava instalado).
     */
    public function retryConversion(Request $request, Document $document, DocumentConversionService $converter): JsonResponse
    {
        Gate::authorize('update', $document);

        if (! $document->status->isEditable() || ! $document->original_file_type->needsConversion()) {
            return response()->json(['message' => 'Só é possível converter documentos Word em rascunho.'], 409);
        }

        if ($document->conversion_status === DocumentConversionStatus::Converted) {
            return response()->json(['message' => 'Este documento já foi convertido para PDF.'], 409);
        }

        $failure = $this->convert($document, $request->user(), $converter);

        return response()->json([
            'message' => $failure ?? 'Word convertido para PDF. Confira o PDF antes de enviar para assinatura.',
            'data' => DocumentResource::make($this->loadDetail($document)),
        ], $failure ? 422 : 200);
    }

    /**
     * Exclusão lógica — só rascunho (nada foi enviado à Clicksign). Um
     * documento que já passou pela Clicksign fica no histórico; para
     * interromper o processo, use o cancelamento.
     */
    public function destroy(Document $document): JsonResponse
    {
        Gate::authorize('delete', $document);

        if ($document->status !== DocumentStatus::Draft) {
            return response()->json(['message' => 'Só é possível excluir documentos em rascunho. Para interromper um envio, cancele o processo.'], 409);
        }

        $document->delete();

        return response()->json(null, 204);
    }

    /**
     * Arquivo ORIGINAL (PDF ou DOCX, como foi enviado), servido pelo Laravel
     * a partir do armazenamento privado (local ou S3) depois da
     * DocumentPolicy — a object key nunca é exposta.
     */
    public function file(Document $document): StreamedResponse
    {
        Gate::authorize('downloadFiles', $document);

        $type = $document->original_file_type;

        return $this->files->response(
            $document->original_file_path,
            $this->downloadName($document, 'original', $type->value),
            $type->mimeType(),
        );
    }

    /**
     * O PDF que vai (ou foi) para a Clicksign: o original, se ele já era
     * PDF; o convertido, se o original é Word. É o que o funcionário revisa
     * antes de enviar.
     */
    public function signingFile(Document $document): StreamedResponse|JsonResponse
    {
        Gate::authorize('downloadFiles', $document);

        $path = $document->signingFilePath();

        if ($path === null) {
            return response()->json(['message' => 'O PDF para assinatura ainda não está disponível: a conversão do Word não foi concluída.'], 409);
        }

        return $this->files->response($path, $this->downloadName($document, 'para-assinatura'), 'application/pdf');
    }

    private function loadDetail(Document $document): Document
    {
        return $document->load(Document::DETAIL_RELATIONS);
    }

    /**
     * Object key com UUID gerado aqui (documents/original/{uuid}.pdf|docx) —
     * o nome enviado pelo usuário nunca vira caminho.
     */
    private function storeFile(UploadedFile $file, DocumentFileType $type): string
    {
        $key = FileKeys::documentOriginal($type->value);
        $this->files->putFile($key, $file, $type->mimeType());

        return $key;
    }

    /** Nome original só para exibição, reduzido a caracteres seguros. */
    private function safeOriginalName(UploadedFile $file, DocumentFileType $type): string
    {
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'contrato';

        return Str::limit($base, 120, '').'.'.$type->value;
    }

    public static function downloadName(Document $document, string $suffix, string $extension = 'pdf'): string
    {
        return (Str::slug(Str::limit($document->title, 80, '')) ?: 'documento')."-{$suffix}.{$extension}";
    }

    /**
     * Converte o Word do documento para o PDF de assinatura e registra o
     * resultado. Devolve a mensagem de falha (amigável) ou null se deu certo.
     * Nunca cria envelope nem fala com a Clicksign.
     */
    private function convert(Document $document, User $user, DocumentConversionService $converter): ?string
    {
        $target = FileKeys::documentSigning();

        try {
            $size = $converter->convertToPdf($document->original_file_path, $target);
        } catch (DocumentConversionException $e) {
            $document->forceFill(['conversion_status' => DocumentConversionStatus::Failed])->save();
            $document->recordEvent(DocumentEventType::ConversionFailed, $e->getMessage(), ['reason' => $e->reason], $user);

            return $e->getMessage();
        }

        $previous = $document->signing_file_path;

        $document->forceFill([
            'signing_file_path' => $target,
            'signing_file_size' => $size,
            'conversion_status' => DocumentConversionStatus::Converted,
        ])->save();

        if ($previous && $previous !== $target) {
            $this->files->delete($previous);
        }

        $document->recordEvent(DocumentEventType::FileConverted, 'Documento Word convertido para PDF. Confira o PDF antes de enviar para assinatura.', user: $user);

        return null;
    }

    /** @param  array<string, mixed>  $data */
    public static function createSigner(Document $document, array $data): DocumentSigner
    {
        $signer = new DocumentSigner([
            'name' => trim(preg_replace('/\s+/', ' ', $data['name'])),
            'email' => isset($data['email']) && $data['email'] !== '' ? mb_strtolower(trim($data['email'])) : null,
            'phone_number' => DocumentSigner::normalizePhone($data['phone_number'] ?? null),
            'channel' => $data['channel'],
        ]);

        $document->signers()->save($signer);

        return $signer;
    }
}
