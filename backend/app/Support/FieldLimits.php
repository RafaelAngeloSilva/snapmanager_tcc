<?php

namespace App\Support;

/**
 * Limites de tamanho dos campos de texto — ponto ÚNICO de verdade do
 * back-end (o front-end espelha estes valores em
 * frontend/src/utils/fieldLimits.js).
 *
 * Cada limite foi escolhido pelo SIGNIFICADO do campo (não um valor único
 * para tudo) e cabe na coluna do banco: varchar(255) para os curtos, text
 * para os longos (onde o limite existe para conter abuso, requisições
 * gigantes e quebra de layout, não por causa da coluna).
 */
final class FieldLimits
{
    // Pessoas e contato
    public const PERSON_NAME = 120;      // nome de usuário, cliente, signatário

    public const EMAIL = 254;            // limite prático de um endereço (RFC 5321)

    public const PHONE = 20;             // "+55 (11) 98765-4321" = 19

    // Dígitos, espaço e + ( ) - . — com PELO MENOS 8 dígitos (sem isso,
    // "--------" passaria).
    public const PHONE_PATTERN = '/^(?=(?:\D*\d){8})\+?[0-9\s().\-]{8,20}$/';

    /** Siglas das 27 unidades federativas. */
    public const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    // Endereço
    public const STREET = 150;

    public const ADDRESS_NUMBER = 20;

    public const COMPLEMENT = 100;

    public const NEIGHBORHOOD = 100;

    public const CITY = 100;

    // Títulos e rótulos
    public const TITLE = 150;            // agendamento, pedido, galeria, card, documento

    public const CATEGORY = 80;          // tipo de ensaio, tipo de serviço

    public const LOCATION = 200;

    public const KANBAN_COLUMN = 60;

    public const WATERMARK_TEXT = 60;    // repetido em diagonal sobre a foto

    // Textos livres
    public const NOTES = 2000;           // observações de cliente/agendamento/pedido

    public const DESCRIPTION = 2000;     // descrição de pedido, galeria, card

    public const DOCUMENT_NOTES = 5000;  // documentos (limite já existente)

    public const STATUS_NOTE = 500;      // observação ao trocar o status do pedido

    // Busca e filtros
    public const SEARCH = 100;

    // Senhas: o bcrypt ignora tudo depois do 72º byte.
    public const PASSWORD_MIN = 8;

    public const PASSWORD_MAX = 72;

    // Valores
    public const MONEY_MAX = 99_999_999.99; // orders.total = decimal(10, 2)

    public const POSITION_MAX = 100_000;     // posição de card/coluna no quadro
}
