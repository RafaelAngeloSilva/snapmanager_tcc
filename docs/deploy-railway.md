# Deploy do backend na Railway

Guia para colocar o backend Laravel do SnapManager no serviço **Backend** do
projeto **SnapManager** na Railway, usando o **Postgres** do mesmo projeto,
o bucket **privado** do Amazon S3 e o LibreOffice para converter DOCX → PDF.

> Nenhum valor secreto aparece neste documento. Segredos (APP_KEY, chaves AWS,
> token da Clicksign, senha do banco) vão **só** nas variáveis do serviço na
> Railway — nunca no Git, no `.env.example` ou em arquivos de configuração.

---

## 1. Resumo das decisões

| Tema | Decisão |
|---|---|
| Build | **Railpack** (builder padrão da Railway). **Sem Dockerfile.** |
| Root Directory | `/backend` |
| PHP | **8.2.x** — o Railpack lê `"php": "^8.2"` do `composer.json` e usa a imagem `dunglas/frankenphp:php8.2.x-trixie` (Debian 13) |
| Servidor | FrankenPHP (Caddy + PHP) gerenciado pelo Railpack, escutando em `$PORT`, raiz em `public/`. Nada de `php artisan serve` |
| LibreOffice | Pacotes APT em `backend/railpack.json` → `/usr/bin/soffice` |
| Migrations | **Pre-Deploy Command** da Railway (uma vez por deploy, antes de liberar o tráfego). A migration automática do Railpack na partida fica **desligada** |
| Cache do Laravel | Feito pelo próprio Railpack (`config/event/route/view:cache` no build e `optimize` a cada partida). Nada a configurar |
| Logs | `LOG_CHANNEL=stderr` → aparecem em **Deploy Logs** |
| Health check | `GET /up` (rota nativa do Laravel 12, já configurada) |
| Arquivos persistentes | S3 (`SNAPMANAGER_FILESYSTEM_DISK=s3`). O disco do container é temporário |

### Por que não é preciso Dockerfile

O provedor PHP do Railpack (código-fonte conferido na versão 0.40.1) já cobre
tudo o que o backend usa:

- **PHP + extensões**: instala as extensões padrão do Laravel (ctype, curl,
  dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo, session,
  tokenizer, xml), as `ext-*` do `composer.json` (**gd** e **zip**, declaradas
  nesta etapa), `pdo_pgsql` (porque `DB_CONNECTION=pgsql`) e o que estiver em
  `RAILPACK_PHP_EXTENSIONS` (**exif**).
- **Composer**: `composer install --optimize-autoloader` no build.
- **Pacotes de sistema**: `deploy.aptPackages` do `railpack.json` (equivale a
  `RAILPACK_DEPLOY_APT_PACKAGES`) são instalados na imagem final.
- **Laravel**: detecta o `artisan`, aponta o servidor para `public/`, ajusta
  permissões de `storage/` e roda os caches.

A única limitação encontrada é de **tamanho**, não de funcionamento: o Railpack
instala pacotes APT com `apt-get install -y` (inclui os pacotes
"recomendados") e não tem opção para desligar isso. O LibreOffice fica com
~880 MB em vez de ~420 MB. O build fica mais lento, mas a conversão funciona
igual. Não justifica manter um Dockerfile.

---

## 2. Arquivos de deploy no repositório

| Arquivo | Para quê |
|---|---|
| `backend/railpack.json` | Pacotes APT do LibreOffice e fontes; desliga a migration automática do Railpack (`RAILPACK_SKIP_MIGRATIONS=true`) |
| `backend/composer.json` | Declara `ext-gd` e `ext-zip` (o Railpack instala as `ext-*` daqui) |
| `backend/config/cors.php` | Origens do front-end por variável |
| `backend/bootstrap/app.php` | Confia no proxy da Railway (HTTPS e IP real do cliente) |

**Não há `railway.json`.** A Railway marcou o "Config as Code"
(`railway.json`/`railway.toml`) como **descontinuado**: serviços novos não o
usam e os arquivos existentes deixam de ser lidos em 2026-12-01. Por isso
Pre-Deploy Command, health check e Root Directory são configurados **no
painel** (seção 4). A alternativa nova da Railway ("Infrastructure as Code",
`.railway/railway.ts`) gerencia o projeto inteiro pela CLI e fica fora do
escopo desta etapa.

### `backend/railpack.json`

```json
{
  "$schema": "https://schema.railpack.com",
  "deploy": {
    "aptPackages": [
      "libreoffice-writer-nogui",
      "fonts-crosextra-carlito",
      "fonts-crosextra-caladea",
      "fonts-liberation"
    ],
    "variables": {
      "RAILPACK_SKIP_MIGRATIONS": "true"
    }
  }
}
```

| Pacote (Debian 13 "trixie") | Por quê |
|---|---|
| `libreoffice-writer-nogui` | Só o Writer, sem interface gráfica (o metapacote `libreoffice` traz Calc, Impress, Draw, Base etc.: ~1,8 GB). Traz `libreoffice-common`, que cria `/usr/bin/soffice` |
| `fonts-crosextra-carlito` | Métricas idênticas à **Calibri** (fonte padrão do Word) |
| `fonts-crosextra-caladea` | Métricas idênticas à **Cambria** |
| `fonts-liberation` | Métricas idênticas a **Arial**, **Times New Roman** e **Courier New** |

Sem as fontes "métrica-compatíveis", o LibreOffice troca Calibri/Arial por
outra fonte com larguras diferentes: quebras de linha e de página do contrato
mudam no PDF.

**Validação feita** (sistema Debian/Ubuntu limpo, instalando exatamente esses
pacotes do mesmo jeito que o Railpack):
- `soffice` em `/usr/bin/soffice` (link para `/usr/lib/libreoffice/program/soffice`);
- conversão real pelo `DocumentConversionService` do projeto: PDF gerado em ~1 s,
  diretório temporário apagado no final;
- no PDF: Calibri → Carlito, Cambria → Caladea, Arial → Liberation Sans,
  Times New Roman → Liberation Serif, todas embutidas; acentos corretos.

A existência dos 4 pacotes no Debian 13 foi conferida no índice oficial
(LibreOffice 25.2.3 no trixie).

> Use **ou** o `railpack.json` **ou** a variável `RAILPACK_DEPLOY_APT_PACKAGES`,
> não os dois. O arquivo é o recomendado: fica versionado junto com o código.

---

## 3. Requisitos auditados

| Item | Resultado |
|---|---|
| PHP | `^8.2` no `composer.json`. A dependência mais exigente do `composer.lock` pede `>=8.2`. Railpack → **8.2.x** |
| `gd` | **Obrigatória.** Intervention Image (driver GD): marca d'água e miniaturas. Também usada pelo DomPDF para imagens |
| `zip` | **Obrigatória.** `App\Support\DocxInspector` abre o .docx (`ZipArchive`) para validar o upload |
| `pdo_pgsql` | **Obrigatória.** Banco PostgreSQL. Instalada pelo Railpack quando `DB_CONNECTION=pgsql` está nas variáveis |
| `exif` | **Recomendada.** O Intervention Image usa EXIF para girar fotos de celular tiradas na vertical. Sem ela, miniaturas e versões com marca d'água podem sair deitadas |
| `dom`, `mbstring`, `xml`, `fileinfo`, `openssl`, `curl`... | Já incluídas pelo Railpack para Laravel |
| `intl` | **Não** é usada pelo código da aplicação; não foi adicionada |
| `proc_open` | Necessário para chamar o LibreOffice (Symfony Process). Habilitado no `php.ini` do Railpack |
| AWS S3 | `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` (PHP puro; nada nativo extra) |
| Clicksign | Cliente HTTP do Laravel (Guzzle/curl). Nada nativo extra |
| Fila / agendador | Nenhum job em fila nem tarefa agendada. **Não** é preciso serviço de worker ou cron |

---

## 4. Passo a passo no painel da Railway

Faça nesta ordem, **depois** do commit/push.

### 4.1 Ligar o repositório
1. Projeto **SnapManager** → serviço **Backend** → **Settings** → **Source** →
   conectar o repositório do GitHub (branch principal).
2. **Root Directory**: `/backend`.
3. **Builder**: Railpack (padrão). Não escolha Dockerfile.

### 4.2 Variáveis (antes do primeiro deploy)
Serviço **Backend** → **Variables** → **Raw Editor**. Cole o bloco abaixo e
troque os `<...>`. Os valores marcados como **secreto** só existem aqui.

```env
APP_NAME=SnapManager
APP_ENV=production
APP_DEBUG=false
APP_KEY=<secreto: saída de "php artisan key:generate --show">
APP_URL=https://<seu-dominio>.up.railway.app
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
APP_FAKER_LOCALE=pt_BR

FRONTEND_URL=<url do front-end; enquanto não houver Vercel: http://localhost:5173>
CORS_ALLOWED_ORIGINS=

DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

LOG_CHANNEL=stderr
LOG_LEVEL=info
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter

SNAPMANAGER_FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<secreto>
AWS_SECRET_ACCESS_KEY=<secreto>
AWS_DEFAULT_REGION=us-east-2
AWS_BUCKET=<nome do bucket>
AWS_USE_PATH_STYLE_ENDPOINT=false

CLICKSIGN_BASE_URL=https://sandbox.clicksign.com/api/v3
CLICKSIGN_ACCESS_TOKEN=<secreto>
CLICKSIGN_TIMEOUT=15
CLICKSIGN_WEBHOOK_SECRET=

DOCUMENT_CONVERTER_BINARY=/usr/bin/soffice
DOCUMENT_CONVERTER_TIMEOUT=90

RAILPACK_PHP_EXTENSIONS=exif

SANCTUM_TOKEN_EXPIRATION=10080
```

Detalhes de cada variável:

| Variável | Valor | Observação |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | **Nunca** `true` em produção (exporia stack traces e configuração) |
| `APP_KEY` | secreto | Gere uma chave **nova** só para produção: `php artisan key:generate --show` (só imprime; não altera seu `.env`). Não troque depois: invalida tokens de redefinição de senha e dados criptografados |
| `APP_URL` | `https://<domínio>` | Domínio gerado na etapa 4.4. Com `https`, todas as URLs geradas pelo Laravel saem em https |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | `pt_BR` | **Obrigatório**: o Railpack define `APP_LOCALE=en` na imagem; sem esta variável as mensagens de validação saem em inglês |
| `FRONTEND_URL` | URL do front | Links de redefinição de senha, retorno do login com Google e **origem liberada no CORS** |
| `CORS_ALLOWED_ORIGINS` | vazio ou lista | Opcional. Origens extras, separadas por vírgula. Vazio = só `FRONTEND_URL`. Nunca `*` |
| `DB_CONNECTION` | `pgsql` | Também faz o Railpack instalar `pdo_pgsql` no build |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` | Referência ao serviço Postgres (rede interna da Railway). Digite exatamente assim; a Railway resolve o valor |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` | Tabelas já criadas pelas migrations. Não use `file`: o disco do container some a cada deploy |
| `LOG_CHANNEL` | `stderr` | Logs vão para **Deploy Logs**; nada fica só no disco temporário |
| `LOG_LEVEL` | `info` | `debug` é muito verboso para produção |
| `LOG_STDERR_FORMATTER` | `Monolog\Formatter\JsonFormatter` | Opcional (recomendado pelo guia de Laravel da Railway): logs em JSON, filtráveis no painel |
| `SNAPMANAGER_FILESYSTEM_DISK` | `s3` | Galerias, documentos, PDFs assinados e fotos de perfil no S3 |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | secretos | Do usuário IAM da aplicação (permissão mínima no bucket) |
| `AWS_DEFAULT_REGION` | `us-east-2` | Região do bucket |
| `AWS_BUCKET` | nome do bucket | Bucket **privado**. Nada é servido direto do S3: tudo passa pela API |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | `AWS_URL` e `AWS_ENDPOINT` ficam **sem definir** (só servem para MinIO/R2) |
| `CLICKSIGN_BASE_URL` | URL do **Sandbox** | Continua no Sandbox. A troca para produção é uma decisão separada |
| `CLICKSIGN_ACCESS_TOKEN` | secreto | Token do Sandbox |
| `CLICKSIGN_WEBHOOK_SECRET` | vazio por enquanto | Preencher quando o webhook for cadastrado (seção 6) |
| `DOCUMENT_CONVERTER_BINARY` | `/usr/bin/soffice` | Caminho no Linux. O código não tem caminho fixo: localmente continua o caminho do Windows no seu `.env` |
| `DOCUMENT_CONVERTER_TIMEOUT` | `90` | Segundos por conversão |
| `RAILPACK_PHP_EXTENSIONS` | `exif` | Variável **de build**: extensão extra (ver seção 3) |
| `REGISTRATION_ENABLED` | (não definir) | Cadastro público pela tela "Criar conta" e pelo Google (cria conta de **atendente**). **Aberto por padrão**. Para fechar, defina `false`: o botão "Criar conta" some sozinho |
| `SANCTUM_TOKEN_EXPIRATION` | `10080` | Validade do login em minutos (7 dias). Depois disso o usuário entra de novo |

Variáveis que **não** devem ser criadas: `RAILPACK_DEPLOY_APT_PACKAGES` (já está
no `railpack.json`), `RAILPACK_SKIP_MIGRATIONS` (idem), `PORT` (a Railway
injeta sozinha), `DB_HOST`/`DB_PASSWORD` etc. (o `DB_URL` já traz tudo).

Login com Google (opcional, pode ficar para depois): `GOOGLE_CLIENT_ID`,
`GOOGLE_CLIENT_SECRET` e `GOOGLE_REDIRECT_URI=https://<domínio>/auth/google/callback`
(**sem** `/api`). A mesma URL precisa estar cadastrada no Google Cloud Console.

### 4.3 Deploy: Pre-Deploy Command e health check
Serviço **Backend** → **Settings** → **Deploy**:

- **Pre-Deploy Command**:
  ```
  php artisan config:clear && php artisan migrate --force
  ```
- **Healthcheck Path**: `/up`
- **Custom Start Command**: deixe **vazio** (o Railpack usa o próprio script de
  partida com FrankenPHP).
- **Replicas**: 1.

Por que o `config:clear` antes do `migrate`: o Railpack gera o cache de
configuração **durante o build**. O Pre-Deploy roda nessa mesma imagem, antes
da partida que recria o cache. Se o cache do build tiver sido gerado sem as
variáveis do banco, o padrão do Laravel é `sqlite`: o `migrate` "funcionaria"
num arquivo SQLite descartável e o Postgres ficaria sem tabelas. O
`config:clear` faz o `migrate` ler as variáveis reais. Testado: Postgres limpo
→ 26 migrations aplicadas; segundo deploy → "Nothing to migrate".

Se o Pre-Deploy falhar, a Railway **não** libera a nova versão (a anterior
continua no ar). As migrations nunca rodam no build e **nunca** com `fresh`;
nenhum seed é executado.

### 4.4 Domínio
**Settings** → **Networking** → **Generate Domain**. Se a Railway pedir a
porta, use a que ela sugerir (a do `PORT` injetado; o servidor escuta nela).
Depois copie o domínio para `APP_URL` (`https://...`).

### 4.5 Primeiro deploy
Clique em **Deploy** (ou faça um push). No **Build Log**, confira:
- `Using config file railpack.json` e `Found Laravel app`;
- `install-php-extensions ...` com **gd**, **zip**, **exif** e **pdo_pgsql**;
- `apt-get install -y ... libreoffice-writer-nogui ...`.

No **Deploy Log** (Pre-Deploy): `Running migrations` (ou `Nothing to migrate`).
Depois: `Starting Laravel server ...`.

### 4.6 Conferências depois do deploy
1. `https://<domínio>/up` → página "Application up" (HTTP 200).
2. `https://<domínio>/api/auth/google/status` → JSON.
3. Pelo terminal do serviço (Railway CLI: `railway ssh`, no serviço Backend):
   ```bash
   php artisan storage:healthcheck            # grava, lê e apaga um objeto de teste no S3
   php artisan clicksign:check                # conexão com o Sandbox da Clicksign
   soffice --version                          # LibreOffice instalado
   ```
4. Pelo front-end local apontando para a Railway (`VITE_API_BASE_URL=https://<domínio>/api`
   no `frontend/.env`, com `FRONTEND_URL=http://localhost:5173` na Railway):
   login, upload de fotos numa galeria, upload de um contrato .docx (deve
   converter para PDF).

### 4.7 Primeiro administrador
O banco de produção não recebe os dados de demonstração (seeders). O quadro de
Produção já nasce com as colunas padrão (migration
`seed_default_kanban_columns`), mas **toda conta criada pelo site ou pelo Google
é de atendimento**. Galerias, colunas do quadro e exclusões são só do
administrador. Depois de criar a sua conta, promova-a pelo terminal do
serviço (`railway ssh`):

```bash
php artisan users:set-role seu-email@exemplo.com admin
```

O mesmo comando muda qualquer conta para `attendant`, `photographer` ou
`editor`.

---

## 5. Como cada requisito de produção ficou

**Logs.** Railpack já define `LOG_CHANNEL=stderr`, e as variáveis acima
confirmam. O Caddy também loga requisições em JSON no stderr. Nenhum log
importante fica no disco do container.

**Migrations.** Só no Pre-Deploy (seção 4.3). A migration automática que o
Railpack faria a cada partida do container está desligada por
`RAILPACK_SKIP_MIGRATIONS=true` no `railpack.json`. Assim ela não roda de novo
em cada reinício e uma falha não derruba a versão que está no ar.

**Cache.** Conferido: as rotas atuais (incluindo as closures de
`routes/web.php`) são compatíveis com `route:cache`; `php artisan optimize`
(config, events, routes, views) roda sem erro e as rotas respondem com o cache
ativo. Nenhum comando de cache precisa ser adicionado: o Railpack já os
executa.

**Health check.** `GET /up` é a rota nativa do Laravel 12 (declarada em
`bootstrap/app.php`): pública, sem autenticação, sem consulta ao banco e sem
escrita. Teste automatizado confirma que não faz nenhuma query.

**Porta.** Nada fixo em 8000. O Caddyfile do Railpack escuta em
`:{$PORT}` (todas as interfaces), com a porta que a Railway injeta.

**HTTPS atrás do proxy.** O HTTPS termina no proxy da Railway e a requisição
chega em HTTP. Para as URLs geradas pela API (miniaturas da galeria pública,
foto de perfil) saírem em `https` e o `$request->ip()` ser o do cliente (usado
nos limites por IP), o Laravel confia **só** no proxy que fez a chamada e **só**
nos cabeçalhos `X-Forwarded-For` e `X-Forwarded-Proto`. Um cliente não
consegue forjar o próprio IP nem o host. Além disso, com `APP_URL` em https,
as URLs são sempre geradas em https.

**CORS.** Origens explícitas por variável (`FRONTEND_URL` /
`CORS_ALLOWED_ORIGINS`), sem credenciais (o front usa token Bearer) e nunca
`*`. Em `APP_ENV=local` continuam liberados localhost e a rede local em
qualquer porta, para desenvolvimento.

**Filesystem efêmero.** Auditado: galerias (originais, miniaturas, versões com
marca d'água), documentos, PDFs assinados e fotos de perfil passam todos por
`App\Services\FileStorage` → S3. No disco do container ficam só temporários
(upload em andamento, diretório de conversão do LibreOffice, apagado no
`finally`) e caches regeneráveis (views compiladas, cache de config). O disco
`public` só é **lido** como fallback de fotos de perfil antigas (anteriores ao
S3).

---

## 6. Depois (fora desta etapa)

- **Webhook da Clicksign**: com o domínio HTTPS ativo, cadastrar
  `https://<domínio>/api/webhooks/clicksign` no painel da Clicksign (Sandbox) e
  colocar o secret gerado em `CLICKSIGN_WEBHOOK_SECRET`.
- **Front-end na Vercel**: `VITE_API_BASE_URL=https://<domínio>/api` na
  Vercel; na Railway, `FRONTEND_URL=https://<projeto>.vercel.app`.
- **E-mail e login com Google**: a Railway bloqueia SMTP nos planos Trial e
  Hobby, então o envio usa a API HTTPS da Brevo (`MAIL_MAILER=brevo`,
  `BREVO_API_KEY`, `MAIL_FROM_ADDRESS`). Sem isso (`MAIL_MAILER` vazio = `log`)
  os e-mails de redefinição de senha **não são enviados**. Passo a passo do
  Google Cloud, da Brevo e das variáveis em [autenticacao.md](autenticacao.md).
- **Expiração de galerias**: o comando `galleries:expire` não está agendado
  (como no ambiente local). Se for automatizar, criar um serviço Cron na
  Railway.

## 7. Observações

- **Uma réplica.** O deploy foi pensado para 1 instância.
- **Cache de config na imagem.** O Railpack gera `config:cache` no build com
  as variáveis do serviço, então os valores ficam dentro da imagem (no
  registry privado da Railway). Na partida, o cache é recriado com as
  variáveis atuais. Se trocar uma variável, faça um redeploy.
- **Tamanho da imagem.** Até ~0,9 GB a mais por causa do LibreOffice e dos pacotes recomendados (seção 1).
- **Limite de tempo do PHP.** O `php.ini` do Railpack usa
  `max_execution_time=30`. O upload de fotos renova esse limite foto a foto
  (`ExecutionTimeBudget`), e a conversão DOCX tem timeout próprio (90 s).

## 8. Checklist de segurança

- [x] `.env` ignorado pelo Git (`.gitignore` da raiz e do backend).
- [x] `APP_DEBUG=false` em produção (e é o padrão do Railpack).
- [x] Nenhum segredo em `composer.json`, `config/`, `railpack.json` ou nesta documentação.
- [x] Nenhuma chave AWS, token da Clicksign ou senha do banco no repositório.
- [x] Bucket continua **privado**; nenhuma URL pública ou pré-assinada.
- [x] CORS sem `*` e sem credenciais.
- [x] Clicksign continua no **Sandbox**.
