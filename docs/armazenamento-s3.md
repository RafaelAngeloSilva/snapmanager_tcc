# Armazenamento de arquivos: disco local e Amazon S3

O SnapManager guarda fotos das galerias, documentos e fotos de perfil num
**armazenamento persistente** escolhido por uma única variável:

| Ambiente        | Variável                             | Onde ficam os arquivos                  |
|-----------------|--------------------------------------|-----------------------------------------|
| Desenvolvimento | `SNAPMANAGER_FILESYSTEM_DISK=local`  | `backend/storage/app/private` (padrão)  |
| Produção        | `SNAPMANAGER_FILESYSTEM_DISK=s3`     | Bucket **privado** no Amazon S3         |

No desenvolvimento nada muda: `php artisan serve` + `npm run dev`, sem AWS.

## 1. Arquitetura

```
React ──► Laravel (autenticação, DocumentPolicy, token da galeria, perfis)
              │
              ▼
        App\Services\FileStorage  ◄── config('filesystems.snapmanager.disk')
              │
      ┌───────┴────────┐
   disco local      bucket S3 privado
```

- **Um único ponto de acesso** (`app/Services/FileStorage.php`). Controllers e
  serviços não sabem se o disco é local ou S3 e não usam `storage_path()`,
  `->path()` nem caminho absoluto para arquivos persistentes (há um teste que
  garante isso).
- **O banco guarda só a object key** (ex.: `galleries/7/originals/{uuid}.jpg`).
  Nunca URL assinada, nunca credencial.
- **Os arquivos são servidos pelo Laravel** (proxy em streaming), depois das
  regras de acesso de cada rota. O navegador nunca recebe credencial AWS nem
  URL do S3, e o bucket nunca é público.
- **Erros**: arquivo inexistente → 404 `"Arquivo não encontrado."`; S3 fora do
  ar/credencial inválida → 503 `"Não foi possível carregar o arquivo. Tente
  novamente."`. O log registra operação, disco, object key e erro
  **sanitizado** (sem secret, sem Access Key, sem assinatura de URL).

### Por que não usar URL pré-assinada (temporaryUrl) do S3?

Avaliado e **não adotado** nesta etapa:

- A galeria pública depende de regras que o Laravel aplica a cada requisição
  (token, marca d'água, arquivo certo para cada situação). Uma URL assinada
  continuaria válida mesmo depois de o link ser revogado ou a foto excluída.
- Uma grade com dezenas de fotos exigiria gerar dezenas de URLs por
  listagem, e elas expirariam enquanto a página estivesse aberta.
- O proxy pelo Laravel já envia `Cache-Control`, então o navegador não pede
  a mesma miniatura de novo.
- Com o proxy, **o bucket não precisa de CORS** (ver seção 7).

**Fotos de perfil** usam URL assinada **do Laravel** (não do S3):
`/api/files/profile-photos/{id}?expires=…&signature=…`. Isso é necessário
porque um `<img>` não envia o token de login. A URL vale cerca de 24 h, é
estável dentro de cada hora (permite cache) e só é entregue a usuários
autenticados.

### Upload direto do navegador ao S3

Não implementado. O fluxo continua **React → Laravel → S3**, preservando
todas as validações (tipo, tamanho, DOCX seguro, limites da galeria). Pode ser
reavaliado se galerias muito grandes tornarem o upload pelo servidor um
gargalo.

## 2. Convenção de object keys

| Key                                                    | Conteúdo                                  |
|--------------------------------------------------------|-------------------------------------------|
| `galleries/{galeria}/originals/{uuid}.jpg`             | Original (só equipe autenticada)          |
| `galleries/{galeria}/thumbnails/{uuid}.jpg`            | Miniatura 480 px sem marca (equipe)       |
| `galleries/{galeria}/watermarked/{uuid}.jpg`           | Versão com marca d'água (cliente)         |
| `galleries/{galeria}/watermarked-thumbnails/{uuid}.jpg`| Miniatura com marca (cliente)             |
| `galleries/{galeria}/previews/{uuid}.jpg`              | Prévia 2000 px (galeria sem marca)        |
| `galleries/{galeria}/watermark-source/{uuid}.png`      | Logo usado como marca d'água              |
| `documents/original/{uuid}.pdf\|docx`                  | Arquivo enviado pelo funcionário          |
| `documents/signing/{uuid}.pdf`                         | PDF convertido do Word (vai à Clicksign)  |
| `documents/signed/{uuid}.pdf`                          | Cópia do PDF assinado (vem da Clicksign)  |
| `profile-photos/{uuid}.jpg`                            | Foto de perfil                            |

O nome do arquivo é sempre um UUID gerado pelo sistema. O nome original só
aparece no download, como nome amigável. **Registros antigos não foram
renomeados**: o banco guarda a key completa de cada arquivo (ex.:
`galleries/7/{uuid}.jpg`, `documents/{uuid}.pdf`), e elas continuam
funcionando. Só os arquivos novos seguem a tabela acima.

## 3. Fluxos

**Upload de fotos.** O PHP recebe o arquivo num temporário local. A partir
dele o sistema gera o original, a versão com marca d'água e as miniaturas, e
envia só os resultados ao armazenamento. Nada é baixado do S3 durante o
upload. Se algo falhar, os objetos já gravados são apagados e nenhum registro
é criado.

**Tempo de execução.** O front-end envia **uma foto por requisição**, em
sequência, com progresso "foto X de N". Cada foto ainda renova o próprio
limite de tempo do PHP (`ExecutionTimeBudget`, 120 s por foto). Antes, um
lote de 15 fotos numa requisição só estourava o limite de 60 s, porque no
Windows o limite conta também a espera pela rede até o S3. Se a requisição
morrer mesmo assim, os objetos já enviados são apagados no encerramento do
PHP. Se parte das fotos falhar, as enviadas ficam salvas e só as que
falharam continuam no modal para reenviar.

**Miniaturas.** A grade usa a key da miniatura gravada no banco, sem consulta
prévia ao S3. Fotos antigas sem miniatura ganham uma no primeiro acesso: o
original é baixado **uma única vez**. Se a key aponta para um objeto que
sumiu, a miniatura é regenerada automaticamente. Para gerar tudo de uma vez:
`php artisan galleries:thumbnails`.

**Marca d'água.** É processada em memória (Intervention Image/GD, bytes de
entrada e bytes de saída). A imagem de logo é lida uma vez por upload. A
galeria pública **nunca** serve o original quando a galeria usa marca d'água.

**Documentos.** O original, o PDF para assinatura e o assinado passam pelo
FileStorage. A `DocumentPolicy` continua sendo aplicada antes de cada
download.

**DOCX → PDF (LibreOffice).** O LibreOffice nunca recebe URL:

```
armazenamento (S3/local) → cópia em diretório temporário isolado
→ LibreOffice (perfil próprio, macros bloqueadas, timeout)
→ PDF temporário → validação "%PDF-" → armazenamento → limpeza (sempre)
```

**Envio à Clicksign.** O PDF é lido do armazenamento **antes** de criar o
envelope. Se o S3 falhar nesse momento, nada é criado na Clicksign e o
documento volta a rascunho. O restante da integração não mudou.

**Exclusão.** Excluir uma foto remove o original, as miniaturas, a versão com
marca d'água e a prévia. Excluir uma **galeria** ou um **documento**
continua sendo exclusão lógica (soft delete) e **mantém os arquivos**, como
já era.

## 4. Instalação do driver S3 (uma vez)

```bash
cd backend
composer require league/flysystem-aws-s3-v3:"^3.0"
```

Isso atualiza `composer.json` e `composer.lock`. Faça commit dos dois. Sem o
pacote, o disco `s3` não inicializa, e o log mostra uma mensagem clara
indicando esse motivo.

## 5. Passos no painel da AWS

1. **Criar o bucket** (S3 → Create bucket):
   - Região: de preferência `sa-east-1` (São Paulo);
   - **Object Ownership: ACLs disabled** (recomendado);
   - **Block all public access: ATIVADO** (as 4 opções);
   - Versioning: opcional (recomendado em produção, para recuperar arquivos apagados por engano);
   - Encryption: **SSE-S3** (padrão da AWS). Se escolher SSE-KMS, a política abaixo precisa de permissões `kms:*` adicionais.
2. **Criar a política IAM** (IAM → Policies → Create → JSON) com o conteúdo da seção 6, trocando `NOME-DO-BUCKET`.
3. **Criar um usuário IAM só para a aplicação** (IAM → Users → Create). Não dê acesso ao console. Anexe **apenas** a política do passo 2.
4. **Gerar a Access Key** desse usuário (Security credentials → Create access key → "Application running outside AWS"). Copie o ID e o secret **direto para as variáveis do Railway**. Não salve em arquivo nem no Git.
5. **Nunca use a conta root** nem credenciais de administrador.

## 6. Política IAM (privilégio mínimo)

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "SnapManagerObjetos",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": [
        "arn:aws:s3:::NOME-DO-BUCKET/galleries/*",
        "arn:aws:s3:::NOME-DO-BUCKET/documents/*",
        "arn:aws:s3:::NOME-DO-BUCKET/profile-photos/*"
      ]
    },
    {
      "Sid": "SnapManagerExistenciaDeObjetos",
      "Effect": "Allow",
      "Action": ["s3:ListBucket"],
      "Resource": "arn:aws:s3:::NOME-DO-BUCKET"
    }
  ]
}
```

- `GetObject`: ler arquivos (inclui a consulta de existência/tamanho, `HeadObject`);
- `PutObject`: gravar uploads, miniaturas, PDFs convertidos e assinados;
- `DeleteObject`: excluir fotos e arquivos substituídos;
- `ListBucket`: sem ele, o S3 responde **403 em vez de 404** para arquivo inexistente, e o sistema não conseguiria diferenciar "arquivo não existe" de "sem permissão". A permissão vale só para este bucket.

Não é necessário: `PutObjectAcl`, `s3:*`, criar/apagar bucket, nem qualquer permissão fora deste bucket.

## 7. CORS do bucket

**Não é necessário.** O navegador nunca acessa o S3 diretamente: downloads,
imagens das galerias e fotos de perfil passam pelo Laravel. Deixe o CORS do
bucket vazio.

Se no futuro forem adotados upload direto ou URLs pré-assinadas, configure
CORS **só** com a origem do front-end (ex.: `https://snapmanager.vercel.app`),
nunca com `AllowedOrigins: *`.

## 8. Railway (backend)

A configuração completa do deploy (variáveis, LibreOffice, migrations,
health check e passos no painel) está em [deploy-railway.md](deploy-railway.md).
Para o armazenamento, o essencial é:

```
SNAPMANAGER_FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...                            # do usuário IAM da aplicação
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-2
AWS_BUCKET=NOME-DO-BUCKET
AWS_USE_PATH_STYLE_ENDPOINT=false
```

- O disco do container Railway é **temporário**. Nele ficam só os temporários
  de processamento (upload em andamento, conversão DOCX), sempre apagados ao
  final. Galerias e documentos vivem no S3.

## 9. Migração dos arquivos existentes

O comando copia para o S3 todos os arquivos referenciados no banco,
**com as mesmas keys**. Por isso o banco não precisa mudar:

```bash
# 0. (recomendado) gerar as miniaturas que faltam, ainda no disco local
php artisan galleries:thumbnails

# 1. Simulação (padrão): nada é enviado. Mostra o que seria enviado,
#    o que já está no destino, conflitos, ausentes e erros.
php artisan storage:migrate-to-s3

# 2. Envio real, só depois de conferir a simulação
php artisan storage:migrate-to-s3 --apply

# 3. Trocar o disco e limpar o cache de configuração
#    SNAPMANAGER_FILESYSTEM_DISK=s3
php artisan config:clear
```

Garantias do comando:

- **Dry-run por padrão**: só envia com `--apply`.
- **Idempotente**: rodar de novo não duplica nada. O que já está no destino com o mesmo tamanho é pulado.
- **Conflito** (existe no destino com tamanho diferente) é reportado e **nunca sobrescrito**.
- **Nunca apaga** a origem nem altera o banco. Os arquivos locais continuam onde estão.
- Cada cópia é conferida (tamanho no destino igual ao da origem).
- Inclui fotos de perfil antigas do disco `public`.

## 10. Teste rápido do S3 real

Usa o mesmo `FileStorage` e a mesma configuração da aplicação. Grava,
confere, lê e apaga um objeto em `galleries/_healthcheck/` (prefixo já
permitido pela política IAM). Mostra disco, região e bucket, nunca
credenciais:

```bash
php artisan storage:healthcheck            # teste básico
php artisan storage:healthcheck --size=5   # também mede a velocidade de upload (5 MB)
```

## 11. Testes

Todos os testes rodam **sem AWS**, com `Storage::fake('s3')`. Nenhum PHPUnit
conecta ao S3 real. Ver `tests/Feature/S3StorageTest.php` e
`tests/Feature/MigrateStorageToS3Test.php`.
