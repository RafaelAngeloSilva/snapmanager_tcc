<?php

namespace App\Support;

/**
 * Orçamento de tempo de execução do PHP para uma UNIDADE de trabalho.
 *
 * Por que existe: o limite do PHP (max_execution_time) vale para a
 * requisição inteira — e, no Windows, conta o tempo REAL, incluindo a espera
 * pela rede. Um upload com várias fotos enviadas ao S3 somava o tempo de
 * todas (original + 3 versões derivadas por foto) e estourava o limite no
 * meio do lote ("Maximum execution time of 60 seconds exceeded").
 *
 * set_time_limit() reinicia o contador a partir de zero; chamado no início
 * de cada foto, dá a cada uma o próprio orçamento, independente de quantas
 * vieram antes. Classe própria (e não a chamada solta) para os testes
 * poderem verificar que o orçamento é renovado a cada foto.
 */
class ExecutionTimeBudget
{
    public function renew(int $seconds): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }
}
