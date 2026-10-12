<?php

use App\Support\AwsCredentials;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Arquivos persistentes do SnapManager
    |--------------------------------------------------------------------------
    |
    | Disco onde ficam fotos das galerias, documentos e fotos de perfil. Todo
    | acesso passa por App\Services\FileStorage, que lê SÓ este valor:
    |
    |   SNAPMANAGER_FILESYSTEM_DISK=local  → storage/app/private (desenvolvimento)
    |   SNAPMANAGER_FILESYSTEM_DISK=s3     → bucket privado no Amazon S3 (produção)
    |
    | Com "s3", as variáveis AWS_* abaixo passam a ser obrigatórias. Ver
    | docs/armazenamento-s3.md.
    |
    */

    'snapmanager' => [
        'disk' => env('SNAPMANAGER_FILESYSTEM_DISK', 'local'),

        // Onde as fotos de perfil ficavam antes (disco "public", servido por
        // storage:link). Só é lido como fallback para fotos antigas.
        'legacy_profile_photo_disk' => 'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Bucket PRIVADO: sem "visibility"/ACL (o bucket bloqueia acesso
        // público; os arquivos são servidos pelo Laravel após as regras de
        // acesso). AWS_ENDPOINT/AWS_USE_PATH_STYLE_ENDPOINT só para serviços
        // compatíveis com S3 (MinIO, R2...); na AWS ficam vazios.
        's3' => [
            'driver' => 's3',
            // AwsCredentials::clean tira espaço, quebra de linha e aspas
            // coladas por engano no painel — a AWS recusa tudo com
            // "AuthorizationHeaderMalformed" quando isso acontece.
            'key' => AwsCredentials::clean(env('AWS_ACCESS_KEY_ID')),
            'secret' => AwsCredentials::clean(env('AWS_SECRET_ACCESS_KEY')),
            'region' => AwsCredentials::clean(env('AWS_DEFAULT_REGION')),
            'bucket' => AwsCredentials::clean(env('AWS_BUCKET')),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
