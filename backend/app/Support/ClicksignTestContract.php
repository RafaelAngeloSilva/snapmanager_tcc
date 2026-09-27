<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF fictício usado só na homologação com o Sandbox da Clicksign.
 *
 * Gerado em memória a cada uso (nada é gravado em disco nem versionado) a
 * partir de resources/views/clicksign/test-contract.blade.php. O conteúdo
 * é inteiramente fictício: nenhum dado de cliente real, e nem o nome/e-mail
 * de quem assina entra no arquivo.
 */
class ClicksignTestContract
{
    public const MIME_TYPE = 'application/pdf';

    public readonly string $reference;

    public function __construct(?string $reference = null)
    {
        $this->reference = $reference ?? 'SNAP-TESTE-'.now()->format('YmdHis');
    }

    public function filename(): string
    {
        return 'contrato-ensaio-teste-'.strtolower($this->reference).'.pdf';
    }

    /** Conteúdo binário do PDF. */
    public function contents(): string
    {
        return Pdf::loadView('clicksign.test-contract', [
            'reference' => $this->reference,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4')->output();
    }
}
