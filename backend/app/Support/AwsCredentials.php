<?php

namespace App\Support;

/**
 * Limpeza e diagnóstico das variáveis da AWS (S3).
 *
 * Ao colar uma chave no painel da hospedagem é comum vir junto um espaço,
 * uma quebra de linha, aspas ou até o nome da variável ("AWS_ACCESS_KEY_ID=…").
 * A AWS então recusa TODA gravação com "AuthorizationHeaderMalformed".
 * clean() remove esse "lixo" das bordas; diagnose() diz o que está errado
 * sem nunca mostrar o valor.
 */
final class AwsCredentials
{
    private const CONFIG_KEYS = [
        'AWS_ACCESS_KEY_ID' => 'key',
        'AWS_SECRET_ACCESS_KEY' => 'secret',
        'AWS_DEFAULT_REGION' => 'region',
        'AWS_BUCKET' => 'bucket',
    ];

    private const PATTERNS = [
        // Chave de acesso: AKIA (usuário IAM) ou ASIA (temporária) + 16.
        'AWS_ACCESS_KEY_ID' => '/^(AKIA|ASIA)[A-Z0-9]{16}$/',
        'AWS_SECRET_ACCESS_KEY' => '/^[A-Za-z0-9\/+=]{40}$/',
        'AWS_DEFAULT_REGION' => '/^[a-z]{2}(-gov)?-[a-z]+-\d$/',
        // Regras de nome de bucket do S3 (3 a 63, minúsculas, números, . e -).
        'AWS_BUCKET' => '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/',
    ];

    public static function clean(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);

        // "AWS_ACCESS_KEY_ID=AKIA…" colado inteiro.
        $value = preg_replace('/^[A-Z_]+\s*=\s*/', '', $value) ?? $value;

        // Aspas em volta (simples, duplas, crase) e espaços que sobrarem.
        $value = trim($value, " \t\n\r\0\x0B\"'`");

        return $value === '' ? null : $value;
    }

    /**
     * Para cada variável: null se está OK; senão, o problema (sem o valor).
     *
     * @return array<string, string|null>
     */
    public static function diagnose(): array
    {
        $result = [];

        // Com a configuração em cache, env() devolve null: usa o valor já
        // carregado (e limpo) do disco s3.
        $cached = app()->configurationIsCached();

        foreach (self::PATTERNS as $name => $pattern) {
            $raw = $cached ? config('filesystems.disks.s3.'.self::CONFIG_KEYS[$name]) : env($name);
            $clean = self::clean($raw);

            $result[$name] = match (true) {
                $clean === null => 'não definida',
                ! preg_match($pattern, $clean) => self::describe($name, $clean),
                $clean !== $raw => 'tinha espaço, aspas ou quebra de linha (ignorados automaticamente; vale corrigir no painel)',
                default => null,
            };
        }

        return $result;
    }

    private static function describe(string $name, string $value): string
    {
        $length = mb_strlen($value);

        $looksLikeSecret = (bool) preg_match(self::PATTERNS['AWS_SECRET_ACCESS_KEY'], $value);
        $looksLikeKeyId = (bool) preg_match(self::PATTERNS['AWS_ACCESS_KEY_ID'], $value);

        return match (true) {
            $name === 'AWS_ACCESS_KEY_ID' && $looksLikeSecret => 'parece ser a chave SECRETA (40 caracteres): AWS_ACCESS_KEY_ID e AWS_SECRET_ACCESS_KEY provavelmente estão trocadas',
            $name === 'AWS_SECRET_ACCESS_KEY' && $looksLikeKeyId => 'parece ser o ID da chave (começa com AKIA): AWS_ACCESS_KEY_ID e AWS_SECRET_ACCESS_KEY provavelmente estão trocadas',
            default => self::formatProblem($name, $length),
        };
    }

    private static function formatProblem(string $name, int $length): string
    {
        return match ($name) {
            'AWS_ACCESS_KEY_ID' => "formato inválido ({$length} caracteres; o esperado são 20, começando com AKIA)",
            'AWS_SECRET_ACCESS_KEY' => "formato inválido ({$length} caracteres; o esperado são 40)",
            'AWS_DEFAULT_REGION' => 'formato inválido (ex.: us-east-2)',
            default => 'formato inválido (só minúsculas, números, ponto e hífen)',
        };
    }
}
