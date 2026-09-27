<?php

namespace App\Support;

use ZipArchive;

/**
 * Confere se um arquivo é, de fato, um documento Word Office Open XML
 * (.docx) seguro para converter — não apenas "um ZIP com extensão .docx".
 *
 * Nada é extraído para o disco: só o índice do ZIP e alguns XMLs pequenos
 * são lidos em memória, com limite de tamanho.
 *
 * Recusa:
 *  - ZIP corrompido ou inconsistente;
 *  - estrutura sem o documento principal do Word;
 *  - arquivos com macros (.docm renomeado, vbaProject.bin);
 *  - "zip bombs" (muitos arquivos, tamanho descompactado ou taxa de
 *    compressão exagerados);
 *  - nomes internos suspeitos (../, caminhos absolutos);
 *  - vínculos externos que o conversor tentaria buscar na rede (imagens
 *    ou modelos remotos) — hiperlinks comuns continuam permitidos.
 */
class DocxInspector
{
    public const MAIN_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

    private const MAX_ENTRIES = 1000;

    private const MAX_UNCOMPRESSED_BYTES = 60 * 1024 * 1024;

    private const MAX_COMPRESSION_RATIO = 200;

    private const MAX_XML_BYTES = 2 * 1024 * 1024;

    /**
     * @return string|null mensagem de erro amigável, ou null se o arquivo é válido
     */
    public static function problem(string $path): ?string
    {
        $invalid = 'O arquivo não é um documento Word (.docx) válido.';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            return $invalid;
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                return $invalid;
            }

            $total = 0;
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    return $invalid;
                }

                $name = $stat['name'];

                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    return $invalid;
                }

                if (strcasecmp(basename($name), 'vbaProject.bin') === 0) {
                    return 'Documentos Word com macros não são aceitos. Salve como .docx comum (sem macros).';
                }

                $total += $stat['size'];

                // Taxa de compressão absurda em um arquivo grande = zip bomb.
                if ($stat['size'] > 1024 * 1024 && $stat['comp_size'] > 0 && $stat['size'] / $stat['comp_size'] > self::MAX_COMPRESSION_RATIO) {
                    return $invalid;
                }

                $names[$name] = $stat['size'];
            }

            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                return 'O documento Word é grande demais para ser convertido.';
            }

            $contentTypes = self::read($zip, $names, '[Content_Types].xml');

            if ($contentTypes === null || ! str_contains($contentTypes, self::MAIN_CONTENT_TYPE)) {
                // Inclui o .docm renomeado: o tipo principal dele é "macroEnabled".
                return str_contains((string) $contentTypes, 'macroEnabled')
                    ? 'Documentos Word com macros não são aceitos. Salve como .docx comum (sem macros).'
                    : $invalid;
            }

            $rootRels = self::read($zip, $names, '_rels/.rels');

            if ($rootRels === null || ! str_contains($rootRels, '/officeDocument')) {
                return $invalid;
            }

            foreach (array_keys($names) as $name) {
                if (! str_ends_with($name, '.rels')) {
                    continue;
                }

                $rels = self::read($zip, $names, $name);

                if ($rels === null) {
                    return $invalid;
                }

                if (self::hasExternalNonHyperlink($rels)) {
                    return 'O documento Word contém vínculos para arquivos externos (imagens ou modelos na internet). Incorpore o conteúdo ao arquivo e envie novamente.';
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, int>  $names
     */
    private static function read(ZipArchive $zip, array $names, string $name): ?string
    {
        if (! isset($names[$name]) || $names[$name] > self::MAX_XML_BYTES) {
            return null;
        }

        $content = $zip->getFromName($name);

        return $content === false ? null : $content;
    }

    /**
     * Relacionamentos com TargetMode="External" fazem o conversor buscar
     * algo fora do arquivo — exceto hiperlinks, que são só texto clicável.
     * Leitura por expressão regular (sem parser XML): nada de entidades.
     */
    private static function hasExternalNonHyperlink(string $rels): bool
    {
        preg_match_all('/<Relationship\b[^>]*>/i', $rels, $matches);

        foreach ($matches[0] as $tag) {
            if (! preg_match('/TargetMode\s*=\s*["\']External["\']/i', $tag)) {
                continue;
            }

            if (! preg_match('/Type\s*=\s*["\'][^"\']*\/hyperlink["\']/i', $tag)) {
                return true;
            }
        }

        return false;
    }
}
