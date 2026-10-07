<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Convenção de OBJECT KEYS do SnapManager (iguais no disco local e no S3).
 *
 * O nome do arquivo é sempre um UUID gerado pelo sistema — o nome enviado
 * pelo usuário nunca vira caminho (ele só aparece no download, como nome
 * amigável). Os prefixos por tipo permitem, se desejado, políticas IAM ou
 * regras de ciclo de vida por prefixo no bucket.
 *
 *   galleries/{galeria}/originals/{uuid}.jpg               original (privado, só equipe)
 *   galleries/{galeria}/thumbnails/{uuid}.jpg              miniatura 480 px sem marca (equipe)
 *   galleries/{galeria}/watermarked/{uuid}.jpg             versão com marca d'água (cliente)
 *   galleries/{galeria}/watermarked-thumbnails/{uuid}.jpg  miniatura com marca (cliente)
 *   galleries/{galeria}/previews/{uuid}.jpg                prévia 2000 px (cliente, galeria sem marca)
 *   galleries/{galeria}/watermark-source/{uuid}.png        logo usado como marca d'água
 *   documents/original/{uuid}.pdf|docx                     arquivo enviado pelo funcionário
 *   documents/signing/{uuid}.pdf                           PDF convertido do Word (vai à Clicksign)
 *   documents/signed/{uuid}.pdf                            cópia do PDF assinado (vem da Clicksign)
 *   profile-photos/{uuid}.jpg                              foto de perfil
 *
 * Registros ANTIGOS continuam válidos: o banco guarda a key completa de cada
 * arquivo (ex.: "galleries/7/{uuid}.jpg", "documents/{uuid}.pdf"), então
 * nada precisa ser renomeado — só os arquivos novos seguem esta convenção.
 * A galeria não tem UUID próprio; o id no prefixo não é exposto a ninguém
 * (o bucket é privado e as keys nunca saem da API).
 */
final class FileKeys
{
    public const GALLERY_ORIGINALS = 'originals';

    public const GALLERY_THUMBNAILS = 'thumbnails';

    public const GALLERY_WATERMARKED = 'watermarked';

    public const GALLERY_WATERMARKED_THUMBNAILS = 'watermarked-thumbnails';

    public const GALLERY_PREVIEWS = 'previews';

    public const GALLERY_WATERMARK_SOURCE = 'watermark-source';

    /** Nome de arquivo novo: UUID + extensão normalizada. */
    public static function newName(string $extension): string
    {
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension)) ?: 'bin';

        return Str::uuid()->toString().'.'.$extension;
    }

    public static function gallery(int $galleryId, string $variant, string $fileName): string
    {
        return "galleries/{$galleryId}/{$variant}/".basename($fileName);
    }

    public static function documentOriginal(string $extension): string
    {
        return 'documents/original/'.self::newName($extension);
    }

    public static function documentSigning(): string
    {
        return 'documents/signing/'.self::newName('pdf');
    }

    public static function documentSigned(): string
    {
        return 'documents/signed/'.self::newName('pdf');
    }

    public static function profilePhoto(string $extension): string
    {
        return 'profile-photos/'.self::newName($extension);
    }
}
