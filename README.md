# SnapManager

Sistema de gestão para estúdios fotográficos, desenvolvido como projeto de TCC. Centraliza cadastro de clientes, agenda de sessões fotográficas, pedidos e status de produção, com um dashboard operacional de indicadores e alertas.

## Descrição

O SnapManager permite que um estúdio fotográfico controle, em um único painel administrativo:

- **Clientes** cadastrados, com dados de contato e documento;
- **Agenda** de sessões fotográficas, com controle de conflito de horário por profissional;
- **Pedidos** (álbuns, mídias digitais, impressões etc.) e seu **status de produção**, com histórico completo de alterações;
- Um **dashboard** com indicadores gerais (clientes, agendamentos, pedidos, financeiro) e **alertas operacionais** (pedidos atrasados, próximos do prazo, prontos para entrega, agendamentos do dia).

## Tecnologias usadas

**Backend**
- PHP 8.2+
- Laravel 12
- Laravel Sanctum (autenticação via token)
- PostgreSQL
- `barryvdh/laravel-dompdf` (geração do relatório operacional em PDF)

**Frontend**
- React 19
- Vite 5
- React Router DOM 7
- Axios
- Recharts (gráficos do Dashboard)
- CSS Modules (sem biblioteca de UI externa)

## Requisitos de ambiente

- PHP >= 8.2 com extensões padrão do Laravel (`intl` incluída, usada por comandos de formatação) **+ extensões `gd`** (marca d'água e miniaturas das fotos de galeria via Intervention Image) **e `zip`** (validação dos contratos .docx). Ambas são declaradas no `composer.json`. No XAMPP, as DLLs já vêm instaladas mas podem estar desabilitadas — descomente `extension=gd` e `extension=zip` no `php.ini` e reinicie o PHP.
- Composer
- PostgreSQL >= 13 rodando localmente (ou acessível via rede)
- Node.js >= 18 (testado com 22) e npm

## Deploy em produção

Backend na Railway (Railpack, PostgreSQL, Amazon S3, LibreOffice): veja [docs/deploy-railway.md](docs/deploy-railway.md).

Login com Google, "Esqueci minha senha" e envio de e-mail (Brevo): veja [docs/autenticacao.md](docs/autenticacao.md).

## Como rodar o backend

```bash
cd backend
composer install
cp .env.example .env        # se ainda não existir
php artisan key:generate
```

Configure a conexão com o banco no `.env` (ajuste usuário/senha conforme seu PostgreSQL local):

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=snapmanager
DB_USERNAME=postgres
DB_PASSWORD=sua_senha
```

Crie o banco `snapmanager` no PostgreSQL (se ainda não existir), depois rode as migrations com os dados de exemplo:

```bash
php artisan migrate:fresh --seed
php artisan serve --port=8000
```

A API fica disponível em `http://localhost:8000/api`.

## Como rodar o frontend

```bash
cd frontend
npm install
```

Confirme a URL da API em `frontend/.env`:

```env
VITE_API_BASE_URL=http://localhost:8000/api
```

```bash
npm run dev
```

O frontend fica disponível em `http://localhost:5173`. É necessário que o backend esteja rodando simultaneamente (porta 8000).

## Usuário de teste

Criado pelo seeder (`php artisan migrate:fresh --seed`):

| E-mail | Senha | Perfil |
|---|---|---|
| `admin@snapmanager.test` | `password` | admin |
| `atendimento@snapmanager.test` | `password` | attendant |
| `fotografo@snapmanager.test` | `password` | photographer |
| `editor@snapmanager.test` | `password` | editor |

## Principais módulos

- **Autenticação** — login via Laravel Sanctum (Bearer token), rotas protegidas, logout, cadastro público (`/register`), recuperação de senha (`/forgot-password` + `/reset-password`) e login com Google (opcional, ver [DEMO.md](DEMO.md)).
- **Dashboard** — resumo geral (clientes, agendamentos, pedidos, financeiro, galerias) e alertas operacionais calculados dinamicamente. Um card de destaque no topo resume o dia em uma frase ("Hoje você tem X agendamento(s)..."), e o botão **Emitir relatório** gera um PDF operacional (`GET /api/dashboard/report`, autenticado, com filtro opcional de período `start_date`/`end_date`) com os mesmos indicadores do resumo/alertas. Três gráficos (`GET /api/dashboard/charts`, dados já agregados pelo Laravel) mostram pedidos por status, faturamento dos últimos 30 dias e agendamentos dos próximos 7 dias.
- **Clientes** — CRUD completo, com busca real no backend (nome, e-mail, telefone, documento) e paginação. Endereço estruturado (CEP/rua/número/complemento/bairro/cidade/UF) com preenchimento automático via ViaCEP (`GET /api/cep/{cep}`, proxeado pelo back-end). Documento com validação de CPF (dígito verificador) no front e no back.
- **Agenda** — CRUD completo de agendamentos, com seleção de cliente e profissional, validação de conflito de horário por profissional, e bloqueio de datas passadas (só ao criar uma data nova — um agendamento já no passado continua editável nos outros campos).
- **Pedidos** — CRUD completo, alteração de status por ação dedicada (`PATCH .../status`), histórico de status exibido na tela de detalhes, filtros por status/cliente/prazo de entrega, e bloqueio de prazo de entrega no passado (mesma regra de "só ao mudar de fato" da agenda).
- **Produção (Kanban)** — quadro colaborativo onde a equipe acompanha o trabalho em andamento. As colunas são criadas pelo estúdio (não são status fixos de código) e os cards vêm de três origens: agendamentos e pedidos já existentes, importados sem duplicar, e demandas avulsas digitadas direto no quadro. Arrastar um card entre colunas persiste a posição no banco. Detalhes na seção [Kanban de produção](#kanban-de-produção).
- **Documentos e Assinaturas** — contratos em PDF vinculados a cliente (e opcionalmente a pedido/agendamento), enviados para assinatura eletrônica pela Clicksign, com signatários por e-mail ou WhatsApp, acompanhamento de status, webhook, sincronização manual e download do documento assinado. Detalhes na seção [Documentos e Assinaturas](#documentos-e-assinaturas).
- **Galerias de fotos** — o estúdio cria uma galeria vinculada a um cliente (e opcionalmente um pedido), envia fotos e gera um link público com token; o cliente escolhe as fotos sem precisar de login. Fotos ficam em disco privado (`storage/app/private`) e só são servidas por rotas controladas — nunca expostas como arquivo público direto. Suporta expiração (`expires_at` + comando `php artisan galleries:expire`, também bloqueada no passado) e encerramento manual. Fotos públicas recebem **marca d'água** automática (texto padrão ou imagem personalizada, via Intervention Image/GD) — o painel interno sempre mostra a foto original. Cada foto tem uma numeração sequencial ("Foto 001"...) e a equipe é avisada com um badge quando o cliente conclui a escolha (`selection_completed_at`). Veja o roteiro completo em [DEMO.md](DEMO.md).

## Segurança e autorização

**Autenticação** — "quem é o usuário": feita pelo **Laravel Sanctum**, com token Bearer (`personal_access_tokens`). O login (`POST /api/login`) emite o token; o front-end guarda em `localStorage` e o envia em todo request (`api/client.js`); um 401 limpa a sessão local e redireciona para `/login`. Toda rota interna fica atrás do middleware `auth:sanctum` (`routes/api.php`) — sem token válido, nem chega a entrar na rota. A galeria pública é a única área sem autenticação, de propósito: acesso por token opaco na própria URL (`{gallery:access_token}`), sem middleware `auth:sanctum` nenhum.

**Autorização** — "o que esse usuário pode fazer": camada separada da autenticação, implementada com um **middleware de perfil** próprio (`App\Http\Middleware\EnsureUserHasRole`, registrado com o alias `role` em `bootstrap/app.php`). Nas rotas, isso aparece como `Route::middleware('role:admin,attendant')->group(...)`. Antes deste projeto, os 4 perfis (`admin`, `attendant`, `photographer`, `editor`) ficavam só armazenados no banco — qualquer usuário autenticado conseguia chamar qualquer endpoint. Agora cada grupo de rotas exige o(s) perfil(is) certo(s); quem não tem, recebe **403 Forbidden** com uma mensagem amigável, direto do Laravel — nunca só escondido no React.

Para clientes, agenda, pedidos, galerias e dashboard optou-se por **middleware de rota** em vez de Policies, porque a regra é inteiramente baseada em perfil ("este endpoint só pode ser chamado por X perfis"), não em posse de um registro específico (não existe, por exemplo, "só o atendente que criou este pedido pode editá-lo"). Policies fazem mais sentido quando a autorização depende dos dados da instância (`$user->id === $order->created_by`).

O **Kanban de produção** é o primeiro módulo em que essa segunda condição realmente aparece: "o fotógrafo pode mexer apenas nos cards atribuídos a ele" depende do card, não só do perfil. Por isso ele usa as **duas** camadas juntas, cada uma no que resolve melhor:

- **Middleware `role`** para o que é decidido só pelo perfil — montar a estrutura do quadro (`role:admin` nas rotas de coluna) e levar agendamentos/pedidos para o quadro (`role:admin,attendant`);
- **Policy** (`App\Policies\KanbanTaskPolicy`) para o que depende do card — `update`, `move` e `delete` liberam se o usuário administra o quadro (admin/atendimento) **ou** é o responsável pelo card. Como a classe base `Controller` do projeto não usa o trait `AuthorizesRequests`, a checagem é feita com `Gate::authorize('move', $task)` direto no controller, que lança `AuthorizationException` → **403**.

Matriz de permissões (ver `routes/api.php` para o mapeamento exato rota-a-rota):

| Recurso / ação | admin | attendant | photographer | editor |
|---|---|---|---|---|
| Clientes — ler | ✓ | ✓ | ✓ | — |
| Clientes — criar/editar/excluir | ✓ | ✓ | — | — |
| Agenda — ler | ✓ | ✓ | ✓ | — |
| Agenda — criar/editar/excluir | ✓ | ✓ | — | — |
| Pedidos — ler | ✓ | ✓ | ✓ | ✓ |
| Pedidos — criar/editar | ✓ | ✓ | — | — |
| Pedidos — excluir | ✓ | — | — | — |
| Pedidos — alterar status/etapa | ✓ | ✓ | ✓ | ✓ |
| Galerias — ler | ✓ | ✓ | ✓ | ✓ |
| Galerias — criar/editar/excluir/encerrar/upload | ✓ | — | — | — |
| Dashboard (resumo/alertas/relatório/gráficos) | ✓ | ✓ | — | — |
| Produção — ver o quadro | ✓ | ✓ | ✓ | ✓ |
| Produção — criar demanda avulsa | ✓ | ✓ | ✓ | ✓ |
| Produção — editar/mover/excluir card | ✓ | ✓ | só os seus | só os seus |
| Produção — importar agendamento/pedido | ✓ | ✓ | — | — |
| Produção — criar/renomear/reordenar/excluir coluna | ✓ | — | — | — |
| Documentos — listar/ver detalhes e histórico | ✓ | ✓ | ✓ (todos) | só os vinculados a pedido |
| Documentos — criar, editar rascunho, signatários | ✓ | ✓ | — | — |
| Documentos — enviar, notificar, sincronizar | ✓ | ✓ | — | — |
| Documentos — baixar PDF original/assinado | ✓ | ✓ | — | — |
| Documentos — cancelar processo, excluir rascunho | ✓ | — | — | — |

As linhas marcadas com "só os seus" são exatamente as que o middleware de perfil **não** consegue expressar — elas dependem do card, e é a Policy que decide.

Decisões que **não** vieram prontas do enunciado e precisaram de critério:
- **Exclusão de pedido é só admin** — o restante do CRUD de pedidos já era "criar/editar" para o atendimento; excluir é uma ação mais sensível (remove um registro financeiro), então ficou mais restrita.
- **Gestão de galerias é só admin** — a sugestão inicial já listava "visualizar galerias" pro atendimento (não "gerenciar"); como nenhum outro perfil ficou responsável por criar/enviar fotos, essa responsabilidade ficou concentrada no admin.
- **Fotógrafo e editor não têm state machine de transição** — eles podem chamar `PATCH /orders/{order}/status` com qualquer valor de `OrderStatus`, não só "capturado"/"em edição" especificamente. O sistema nunca teve uma regra de transição de estados (o endpoint sempre aceitou qualquer status válido, de qualquer status atual); adicionar essa restrição agora seria inventar uma regra de negócio nova, não corrigir uma existente.

O front-end (`frontend/src/utils/permissions.js` + `useAuth().can(...)`/`hasRole(...)`) espelha essa mesma matriz só para **esconder/desabilitar** o que a pessoa não pode fazer (itens do menu, botões de ação, rotas inteiras redirecionando para `/pedidos`) — nunca é a camada de segurança. Mesmo que esse arquivo divirja do back-end por engano, o Laravel continua bloqueando a ação com 403.

### Validação, limites e proteção contra abuso

- **Back-end é a validação final.** Todo endpoint de escrita usa Form Request; o React só repete as regras para conforto (aviso antes de enviar, `maxLength`, contador de caracteres).
- **Limites de texto centralizados** em `App\Support\FieldLimits` (espelhado em `frontend/src/utils/fieldLimits.js`): nome 120, título 150, tipo de ensaio/serviço 80, observações/descrições 2000, observação de status 500, documentos 5000, busca 100. Telefone só aceita dígitos, espaço, `+ ( ) - .`.
- **Valores em reais** pela regra `App\Rules\Money`: 0 a 99.999.999,99 (a coluna é `decimal(10,2)`), até 2 casas, sem notação científica/NaN/Infinity; `-0` é normalizado para `0`. Posições do Kanban: inteiros de 0 a 100.000.
- **Vínculos coerentes**: não se cria nada para cliente excluído; o agendamento de um pedido e o pedido de uma galeria precisam ser do mesmo cliente.
- **Excluir cliente** (soft delete) cancela, na mesma transação, os agendamentos dele ainda agendados/confirmados; concluídos e já cancelados viram histórico intacto.
- **Senha**: 8 a 72 caracteres (limite real do bcrypt), nova senha diferente da atual (comparada com o hash), troca de senha encerra as outras sessões e redefinição encerra todas. Tokens expiram em 7 dias (`SANCTUM_TOKEN_EXPIRATION`).
- **Cadastro público** pela tela "Criar conta" ou pelo Google, aberto por padrão: sempre cria conta de **atendente** (o perfil enviado é ignorado). `REGISTRATION_ENABLED=false` fecha os dois.
- **Rate limit** (`AppServiceProvider::configureRateLimiting`): login 5/min por e-mail+IP e 20/min por IP; cadastro 10/h; esqueci/redefinir senha 5/min; troca de senha 5/min; uploads 120/min; galeria pública 600 leituras e 20 seleções por minuto.
- **Filtros de listagem** (`?client_id=`, `?search=`...) são validados antes da consulta — texto ou array em coluna numérica responde 422, nunca 500.

## Enums

Status, perfis e outras opções fechadas (`orders.status`, `appointments.status`, `galleries.status`, `galleries.watermark_type`, `users.role`, `kanban_tasks.priority`) são representados na aplicação por **PHP Backed Enums** (`App\Enums\*`), usados como `casts()` nos models correspondentes. Antes, esses valores viviam espalhados como strings mágicas (`Rule::in(['scheduled', 'confirmed', ...])` duplicado em dois Form Requests de agenda, por exemplo) ou em constantes de array soltas nos models (`Order::STATUSES`).

O banco **continua** guardando texto simples (`varchar`), sem `ENUM` nativo do PostgreSQL — essa foi uma escolha deliberada, não uma limitação:
- adicionar um novo valor não exige `ALTER TYPE` (migration de enum nativo do Postgres é notoriamente incômoda);
- o enum vive só na camada de aplicação, então o cast do Eloquent (`'status' => OrderStatus::class`) já garante tipagem e validação sem exigir suporte específico do driver do banco;
- `seeders`/factories/scripts que ainda escrevam a string bruta (`'status' => 'pending'`) continuam funcionando sem tradução — o cast aceita tanto o valor bruto quanto a instância do enum na escrita.

Fluxo completo de um valor, ex. status de pedido:

```
Banco (varchar):        "in_editing"
PHP (Model::casts()):    OrderStatus::InEditing   (App\Enums\OrderStatus)
Validação (Request):     Rule::enum(OrderStatus::class)
Frontend (label):        "Em edição"              (utils/orderStatus.js, inalterado)
```

A API continua recebendo e devolvendo os mesmos códigos de sempre (`"in_editing"`, não `"Em edição"`) — enums do PHP são nativamente serializados pelo `json_encode` como o valor puro, então nenhum contrato de API mudou. O front-end nunca viu (nem precisa ver) o enum do PHP; ele já tinha sua própria tradução para label, que continua exatamente igual.

## Kanban de produção

A tela **Produção** (`/producao`) é um quadro kanban ligado aos dados reais do sistema — não é um quadro de tarefas genérico ao lado do SnapManager.

### Colunas são dados, não código

As colunas vivem na tabela `kanban_columns` (`name`, `position`, `is_done`). O estúdio cria, renomeia, reordena e exclui as suas próprias etapas ("Agendado", "Em edição", "Aguardando cliente"...) sem tocar em código. A flag **`is_done`** marca uma coluna como "de conclusão": ao entrar nela, o card recebe `completed_at`; ao sair, o campo é limpo. Isso evita um segundo campo de status paralelo às colunas.

Excluir coluna **nunca** apaga cards em silêncio: se a coluna tem tarefas, o `DestroyKanbanColumnRequest` exige `move_tasks_to` e devolve **422** sem ele. O quadro também não pode ficar sem nenhuma coluna (**409**).

### A origem de um card é derivada, não declarada

Um card (`kanban_tasks`) pode ter `appointment_id`, `order_id`, `client_id` e `gallery_id` — todas **chaves estrangeiras reais**, com `nullOnDelete`. Não existe par polimórfico `source_type`/`source_id`: ele foi considerado e descartado, porque um `source_id` genérico não pode ter integridade referencial no banco (o Postgres não consegue validar uma FK que aponta para "alguma" tabela). Com FKs explícitas, o banco garante o vínculo, e a origem sai de um `match` de 3 linhas no model:

```php
public function origin(): KanbanTaskOrigin
{
    return match (true) {
        $this->appointment_id !== null => KanbanTaskOrigin::Appointment,
        $this->order_id !== null => KanbanTaskOrigin::Order,
        default => KanbanTaskOrigin::Manual,
    };
}
```

`KanbanTaskOrigin` continua sendo um Enum (com `label()`), só que calculado — ele aparece na API (`"origin": "appointment"`) sem existir como coluna.

### Importar não duplica

`POST /api/appointments/{appointment}/kanban` e `POST /api/orders/{order}/kanban` copiam título, cliente, prazo e responsável do registro de origem para um card novo. Chamar de novo para o mesmo registro devolve **409** com o card já existente no corpo da resposta, para a interface poder oferecer "ver no quadro" em vez de criar um segundo card.

A garantia real não é o `if` do controller e sim o banco — dois índices únicos parciais criados na migration:

```sql
CREATE UNIQUE INDEX kanban_tasks_appointment_unique ON kanban_tasks (appointment_id)
  WHERE appointment_id IS NOT NULL AND deleted_at IS NULL;
```

O `WHERE` é o que permite ter muitos cards manuais (todos com `appointment_id` nulo) e, ao mesmo tempo, no máximo um card ativo por agendamento. Como o card usa soft delete, o índice ignora os excluídos: um card removido pode ser importado de novo.

### Coluna do quadro ≠ status do pedido

Nesta primeira versão, mover um card **não** altera o `status` do pedido vinculado, e alterar o status do pedido não move o card. O vínculo é preservado (o card leva ao pedido, e a tela de detalhes deixa isso explícito), mas as duas regras são independentes: o status do pedido é um contrato com o cliente, a coluna é organização interna da equipe, e sincronizar os dois automaticamente exigiria um mapeamento coluna→status que o estúdio ainda não definiu. Existe um teste que fixa esse comportamento (`moving a card does not change the order status`).

### Posição: inteiros normalizados

`position` é um inteiro sequencial (0, 1, 2...) por coluna. A cada movimento, o `KanbanService` recalcula as posições da coluna de origem e da de destino **dentro de uma transação**, então nunca sobram buracos ou empates. Índices fracionários (inserir 1.5 entre 1 e 2) evitariam reescrever as linhas vizinhas, mas com dezenas de cards por coluna a diferença é irrelevante e a numeração inteira é muito mais fácil de conferir direto no banco.

### Arrastar é instantâneo; salvar vem depois

O quadro usa **@dnd-kit** (`core` + `sortable`). Ao soltar um card, a ordem: (1) o estado local muda na hora; (2) a API é chamada; (3) se ela falhar, o quadro volta para o arranjo exato de antes do arrasto (um snapshot tirado no `onDragStart`) e a mensagem de erro aparece. O usuário nunca espera a rede para ver o card mudar de lugar. O card em si é `memo()`-izado porque o quadro re-renderiza a cada frame do arrasto.

### Endpoints

| Método | Rota | Quem pode |
|---|---|---|
| `GET` | `/api/kanban` | 4 perfis |
| `POST` | `/api/kanban/tasks` | 4 perfis |
| `GET/PUT/DELETE` | `/api/kanban/tasks/{task}` | Policy (dono ou admin/atendimento) |
| `PATCH` | `/api/kanban/tasks/{task}/move` | Policy (dono ou admin/atendimento) |
| `POST` | `/api/appointments/{appointment}/kanban` | admin, attendant |
| `POST` | `/api/orders/{order}/kanban` | admin, attendant |
| `POST/PUT/DELETE` | `/api/kanban/columns[/{column}]` | admin |
| `PATCH` | `/api/kanban/columns/reorder` | admin |

Os filtros (responsável, "Minhas tarefas", prioridade, origem, cliente) são aplicados **na query**, não no navegador: viram condições do `SELECT` dentro do eager load, então o front-end nunca recebe cards que não vai mostrar. O custo em queries é fixo (colunas + tarefas + um por relacionamento), independente do número de cards.

### Ordem das colunas

A reordenação de colunas é feita por botões ◀ ▶ no cabeçalho (visíveis só para admin), e não por arrasto: dentro do quadro, arrastar já significa "mover card", e sobrecarregar o mesmo gesto tornaria fácil bagunçar a estrutura sem querer. Os botões também funcionam por teclado. A persistência usa `PATCH /api/kanban/columns/reorder`, que recebe a lista de ids na ordem desejada.

## Integração Clicksign (assinatura eletrônica) — etapa 1

Primeiras etapas do módulo **Documentos e Assinaturas Eletrônicas**: a comunicação segura entre o Laravel e a **Clicksign API 3.0** (etapa 1) e o fluxo completo de envelope, validado por um comando de homologação (etapa 2). Ainda **não** existem tabelas de documentos, upload de contratos pela interface, telas nem webhooks — nada no banco foi alterado.

### Configuração local

1. No painel do **Sandbox** da Clicksign (`sandbox.clicksign.com`, não o de produção), gere um Access Token de API.
2. No `backend/.env` (nunca no `.env.example`, no código ou no front-end):

   ```env
   CLICKSIGN_BASE_URL=https://sandbox.clicksign.com/api/v3
   CLICKSIGN_ACCESS_TOKEN=cole-o-token-aqui
   CLICKSIGN_TIMEOUT=15   # opcional, em segundos
   ```

3. Se o projeto estiver com configuração em cache, limpe: `php artisan config:clear`.
4. Teste a conexão:

   ```bash
   php artisan clicksign:check
   ```

   O comando é **somente leitura** (`GET /envelopes`): não cria nem altera nada na conta, e imprime só o host, o status HTTP e a quantidade de envelopes — nunca o token. Uma conta sem envelopes responde com lista vazia, o que também conta como sucesso.

### Como funciona

- **`config/services.php` → `clicksign`**: o token é lido só via `config()`, nunca com `env()` direto no código (o `env()` para de funcionar depois de `config:cache`). Sem `CLICKSIGN_BASE_URL`, o padrão é o **Sandbox**, de modo que ninguém aponta para produção por acidente.
- **`App\Services\ClicksignService`**: usa o HTTP Client nativo do Laravel. Conforme a documentação oficial da API 3.0, o token vai **cru** no header `Authorization` (sem `Bearer`), com `Accept` e `Content-Type` `application/vnd.api+json` (JSON:API). A API também aceita `?access_token=` na URL, mas isso é evitado de propósito: URLs vão parar em logs de acesso e em mensagens de exceção. Todas as operações futuras (documentos, envelopes, signatários) passam pelo mesmo método `send()` e herdam autenticação, timeout e tratamento de erro.
- **Erros tipados**: toda falha vira uma `App\Exceptions\ClicksignException` com um `App\Enums\ClicksignErrorReason`:

  | Situação | Motivo |
  |---|---|
  | Token/URL ausente — nenhuma requisição é feita | `not_configured` |
  | HTTP 401 | `unauthorized` |
  | HTTP 403 | `forbidden` |
  | HTTP 5xx, timeout ou falha de rede | `unavailable` |
  | Outros erros (422, 404...) | `request_failed` |

- **O token nunca vaza**: a mensagem da exceção é montada só com o motivo, o status e o `detail` JSON:API da Clicksign (limitado a 300 caracteres e com o token mascarado, caso a API o ecoe). O corpo bruto da resposta nunca é repassado. Em falha de rede, a exceção original do Guzzle **não** é encadeada, porque o objeto de request dela carrega o header `Authorization`. O log de falha registra só método, caminho, status e motivo.
- **Sem endpoint de diagnóstico**: o teste de conexão é um comando Artisan, que só roda quem tem acesso ao servidor. Nenhuma rota nova foi criada na API.

### Etapa 2 — fluxo de envelope (homologação)

O `ClicksignService` ganhou um método por operação da API 3.0, na ordem da receita oficial "Criação e configuração do envelope". Todos reaproveitam o mesmo `send()` da etapa 1, então herdam autenticação, headers JSON:API, timeout e tratamento de erro sem nenhuma alteração neles:

| # | Método | Chamada |
|---|---|---|
| 1 | `createEnvelope($name, $attrs)` | `POST /envelopes` |
| 2 | `addDocument($envelopeId, $filename, $contents)` | `POST /envelopes/{id}/documents` |
| 3 | `addSigner($envelopeId, $attrs)` | `POST /envelopes/{id}/signers` |
| 4 | `addQualificationRequirement(...)` | `POST /envelopes/{id}/requirements` — `action: agree` + `role` |
| 5 | `addAuthenticationRequirement(...)` | `POST /envelopes/{id}/requirements` — `action: provide_evidence` + `auth` |
| 6 | `activateEnvelope($envelopeId)` | `PATCH /envelopes/{id}` — `status: running` |
| 7 | `notifyEnvelope($envelopeId)` | `POST /envelopes/{id}/notifications` |
| — | `getEnvelope`, `listSigners`, `listRequirements` | `GET` correspondentes |
| — | `deleteEnvelope($envelopeId)` | `DELETE /envelopes/{id}` — só funciona em rascunho |

Pontos que vieram da documentação e não são óbvios:

- **`content_base64` precisa do prefixo data URI**: `data:application/pdf;base64,<conteúdo>`, conforme a página "Documentos". O método recebe o conteúdo bruto do arquivo e monta o prefixo sozinho.
- **Não existe URL de assinatura na API 3.0**: o recurso do signatário só tem o link `self` da própria API. O signatário recebe o link por e-mail (ou WhatsApp/SMS, conforme `communicate_events`).
- **A documentação não diz se a ativação já envia e-mail**: a receita trata a notificação como passo separado, mas nada garante que ativar seja silencioso. Por isso o código trata a **ativação** como o momento irreversível.
- **Só rascunho pode ser excluído**: fora de `draft`, o `DELETE` responde 403.

Os valores fechados da API viraram Enums: `ClicksignEnvelopeStatus` (`draft`, `running`, `closed`, `canceled`), `ClicksignSignerRole` (`sign`, `party`, `contractor`) e `ClicksignAuthMethod` (`email`, `sms`, `whatsapp`). A API aceita outros métodos de autenticação (pix, selfie, biometria...), mas vários têm custo ou exigem configuração da conta. Entram como novos cases quando forem usados.

Os IDs vão para a URL com `rawurlencode`, então um ID vindo de input nunca altera o caminho da requisição.

#### Comando de homologação

```bash
php artisan clicksign:test-envelope --name="Seu Nome Completo" --email="seu-email@exemplo.com"
```

Cria um envelope com um PDF **fictício** ("Contrato de Ensaio Fotográfico — Teste SnapManager", gerado em memória por `App\Support\ClicksignTestContract`, com carimbo "sem valor jurídico" e nenhum dado pessoal). As travas de segurança:

- só roda com o host `sandbox.clicksign.com`: com a URL de produção, recusa antes de qualquer chamada;
- **três confirmações separadas**, todas com "não" como padrão: (1) criar o rascunho, (2) ativar, (3) notificar. Com `--no-interaction`, ou apertando Enter, nada é criado;
- até a confirmação 2 o envelope é só rascunho: ninguém recebe nada, e ele pode ser excluído;
- se alguma etapa falhar no meio, o comando mostra qual foi, os IDs já criados, e oferece excluir o rascunho;
- o signatário não precisa informar CPF (`has_documentation: false`): o teste não coleta documento pessoal.

### Testes

`tests/Feature/ClicksignEnvelopeFlowTest.php` (17 testes) confere método, caminho e corpo JSON:API de cada operação contra a documentação, além de erro de autenticação, validação (422), indisponibilidade e falha de conexão. `tests/Feature/ClicksignTestEnvelopeCommandTest.php` (17 testes) cobre as travas do comando (nada é enviado sem confirmação, fora do Sandbox, ou com signatário inválido), a **ordem exata** das chamadas no fluxo completo e as falhas parciais em cada etapa. Tudo com `Http::fake()` e token fictício: nenhum envelope real é criado pelo PHPUnit.

`tests/Feature/ClicksignServiceTest.php` (14 testes) usa `Http::fake()` com um token fictício e `Http::preventStrayRequests()`: se alguma requisição escapar do fake, o teste falha em vez de chamar a Clicksign de verdade. Os testes cobrem sucesso e headers exatos, token ausente/em branco, 401, 403, 5xx, falha de conexão, outros 4xx, mascaramento do token na exceção e no log, e a saída do comando `clicksign:check`.

## Calendário único

Todo campo de data editável do SnapManager usa o **mesmo** componente (`molecules/DateTimePicker`) — não existe um calendário por módulo. Ele tem dois modos, e cada um envia exatamente o formato que o input nativo enviava, então nenhuma validação, payload ou regra de negócio mudou:

| Modo | Formato | Onde | Significado |
|---|---|---|---|
| `datetime` | `YYYY-MM-DDTHH:mm` | Agenda (início/término) | horário do estúdio |
| `datetime` + conversão no envio | instante ISO com fuso | Galerias (validade) | prazo absoluto, comparado com "agora" |
| `date` | `YYYY-MM-DD` | Pedidos (prazo e filtro), Documentos (prazo de assinatura), Produção (prazo do card) | data civil, sem conversão de fuso |

Por que os três tipos são tratados de forma diferente:

- **Data civil** (`mode="date"`): é um dia no calendário, não um instante. O valor viaja como `YYYY-MM-DD` e não passa por conversão nenhuma — por isso o dia escolhido é sempre o dia gravado, sem o clássico "um dia a menos".
- **Horário do estúdio** (Agenda): o sistema inteiro trata `starts_at`/`ends_at` como hora de parede — o Dashboard agrupa por `date(starts_at)`, os alertas do dia e a checagem de conflito comparam os valores gravados. Por isso o formato do envio foi mantido: o calendário mudou a aparência, não o contrato.
- **Instante absoluto** (validade da galeria): é comparado com o "agora" do servidor, então o formulário envia o instante com fuso e o back-end normaliza (ver a seção da galeria).

Os limites de cada campo continuam vindo das regras existentes e agora aparecem no próprio calendário: dias fora do intervalo ficam desabilitados (ex.: prazo de assinatura só entre amanhã e 90 dias, término do agendamento nunca antes do início).

## Validade e compartilhamento da galeria

- **Calendário próprio** (`molecules/DateTimePicker`): dia e horário no mesmo painel, com destaque para hoje (contorno) e para o dia escolhido (preenchido), navegação por mês, atalhos de horário, temas claro/escuro, foco visível e setas do teclado. Substitui o seletor padrão do navegador sem mudar o formato do valor, então a validação continua a mesma. O painel é aberto por portal, para não ser cortado pelo modal. É o **mesmo componente usado em todo o sistema** — ver [Calendário único](#calendário-único).
- **"Expirar em 48 horas"**: um clique preenche a validade com o instante de agora + 48 h. É só preenchimento — dá para trocar a data depois, e nada muda em galerias já existentes sem ação do funcionário.
- **Fuso horário**: o formulário passou a enviar o **instante** escolhido (ISO-8601 com fuso), e as requisições de galeria normalizam `expires_at` para o fuso da aplicação antes de validar. Antes, "18:00" escolhido em Brasília era gravado como 18:00 UTC: a galeria expirava três horas antes do combinado, e um horário válido do fim da tarde podia até ser recusado como "no passado". Galerias já gravadas não foram tocadas.
- **"Enviar por WhatsApp"**, ao lado de "Copiar link público": abre o WhatsApp com a mensagem pronta (saudação com o primeiro nome do cliente, título da galeria, link público e, quando houver, até quando fica disponível). Usa o telefone do cliente quando ele é um número brasileiro válido; sem telefone, abre para escolher o contato. É só o link `wa.me` — **sem API do WhatsApp Business, sem custo e sem envio automático**: quem confirma o envio é o funcionário, dentro do WhatsApp. O telefone do cliente é exposto apenas na rota interna (autenticada); a galeria pública continua sem nenhum dado de contato.

## SnapSwipe — seleção por gestos

Na galeria pública, o cliente escolhe entre **Galeria** (a grade de sempre) e **SnapSwipe**, onde avalia uma foto por vez deslizando o dedo. O link público, o token, a marca d'água e o fluxo de finalização continuam exatamente os mesmos.

- **Direita seleciona, esquerda passa**, com selo "✓ Selecionar" / "✕ Passar" aparecendo conforme o movimento. No computador funciona igual arrastando com o mouse, pelos botões **Passar** e **Selecionar**, ou pelas setas <kbd>←</kbd> <kbd>→</kbd> (e <kbd>U</kbd> para desfazer). Nada depende exclusivamente do gesto.
- **Passar não apaga nada**: significa "não selecionar agora". A foto continua na galeria e pode ser retomada na revisão.
- **Desfazer** volta à foto anterior e restaura exatamente a decisão dela — nenhuma outra é afetada.
- **Ampliar** abre o mesmo lightbox da galeria tradicional (versão cheia, com marca d'água) para conferir detalhes antes de decidir. Um toque simples no cartão também amplia; ampliar nunca seleciona.
- **Progresso**: "Foto X de Y", quantas foram selecionadas e quantas faltam avaliar — contagens diferentes e mostradas separadamente.
- **Fim da sequência**: aparece um resumo com *Revisar selecionadas*, *Rever todas as fotos* e *Finalizar seleção*. Chegar ao último cartão **não** finaliza nada: a finalização continua sendo confirmação explícita, pelo mesmo endpoint de sempre.
- **Revisão**: lista com filtros (selecionadas, não selecionadas, todas), onde dá para trocar a seleção ou voltar ao cartão de uma foto específica sem recomeçar a galeria.

### Uma seleção só

Os dois modos leem e escrevem o **mesmo** estado: a lista de fotos escolhidas da página pública, gravada em `gallery_images.is_selected` pela confirmação já existente. Selecionar no SnapSwipe aparece marcado na grade, e remover na grade reflete no SnapSwipe. **Não existe segunda tabela de seleção, endpoint novo nem migration.**

O que é exclusivo do SnapSwipe são as *decisões de navegação* ("já avaliei esta foto"), que valem só para a sessão, não mudam nada no servidor e por isso não foram persistidas. O modo escolhido (Galeria/SnapSwipe) é lembrado no `sessionStorage` da aba.

### Desempenho

Só dois cartões ficam montados (o atual e o seguinte), e as próximas miniaturas são pré-carregadas em silêncio — uma galeria de centenas de fotos não vira centenas de elementos na tela. Os cartões usam as **miniaturas já existentes** (máx. 480 px, com marca d'água); a versão cheia só é baixada quando o cliente amplia. Trocar de modo não dispara requisição nova.

### Mobile-first e acessibilidade

Altura do baralho em `dvh` (acompanha a barra do navegador), botões na área do polegar, respeito à área segura inferior, `object-fit: contain` com fundo neutro para não cortar retratos, paisagens ou quadradas, e `touch-action: pan-y` para o gesto horizontal não atrapalhar a rolagem da página. Todos os botões têm ícone **e** rótulo, o foco é visível e a animação é desligada com "movimento reduzido".

## Documentos e Assinaturas

Etapa 3 do módulo: a tela **Documentos** (`/documentos`) leva um contrato do upload até o PDF assinado, usando o `ClicksignService` das etapas anteriores (no **Sandbox** da Clicksign, API 3.0).

### Fluxo

1. **Novo documento**: título, tipo, cliente, pedido/agendamento opcionais (só do mesmo cliente), PDF, prazo, observações e signatários. Salva como **rascunho**: nada vai para a Clicksign.
2. **Enviar para assinatura**: cria o envelope, envia o PDF, cadastra os signatários, cria os requisitos (qualificação + autenticação) e **ativa** o envelope. Cada passo é gravado assim que conclui.
3. **Enviar solicitação**: chama explicitamente o endpoint oficial de notificação, e cada signatário recebe o convite pelo seu canal. É uma ação **separada** da ativação (como na receita oficial), com proteção contra duplo clique: botão em loading, bloqueio no banco e intervalo mínimo de 60 s entre envios. Depois vira **Reenviar solicitação**.
4. **Acompanhamento**: por **webhook** (automático, quando há URL pública) ou pelo botão **Sincronizar status**.
5. **Baixar documento assinado**, quando o envelope é finalizado.

### E-mail e WhatsApp

Cada signatário escolhe um canal. O `communicate_events` enviado à Clicksign segue a documentação da API 3.0 (`App\Enums\SignerChannel`):

| Canal | signature_request | signature_reminder | document_signed | Autenticação | Contato obrigatório |
|---|---|---|---|---|---|
| E-mail | `email` | `email` | `email` | token por `email` | e-mail |
| WhatsApp | `whatsapp` | `none` (1) | `whatsapp` | token por `whatsapp` | telefone |

(1) A API só aceita lembrete por `email` ou `none`, **não existe lembrete por WhatsApp**. O telefone é normalizado para o formato exigido (DDD + número, só dígitos, sem DDI): `+55 (11) 98765-4321` vira `11987654321`.

### Status: local × Clicksign

O status local não é cópia do remoto: combina o envelope, as assinaturas e o que aconteceu do nosso lado (`App\Enums\DocumentStatus`).

| Local | Quando |
|---|---|
| `draft` | nada foi enviado ainda |
| `preparing` | envio em andamento |
| `integration_error` | o envio parou no meio (etapa e mensagem ficam registradas) |
| `awaiting_signature` | envelope `running`, nenhuma assinatura |
| `partially_signed` | envelope `running`, ao menos uma assinatura (badge "1 de 2 assinaturas") |
| `signed` | envelope/documento **`closed`**, e só isso conta como concluído |
| `canceled` / `declined` / `expired` | eventos `cancel` / `refusal` / `deadline` |

Todas as assinaturas coletadas **não** equivalem a `signed`: a conclusão só vale quando a Clicksign fecha o envelope (com `auto_close`, logo após a última assinatura). Estados finais nunca são desfeitos por eventos atrasados ou fora de ordem.

### Falhas no meio do envio

Se uma chamada falhar (ex.: envelope criado, signatário recusado), o documento vai para `integration_error` com a **etapa** e a **mensagem sanitizada** da Clicksign, e o histórico ganha um evento `integration_error`. O registro local nunca é apagado. A partir daí:

- **Descartar envio**: confere que o envelope remoto ainda é rascunho, exclui na Clicksign e devolve o documento a `draft`, para corrigir e reenviar;
- **Sincronizar status**: se a ativação chegou a acontecer (ex.: a resposta se perdeu por timeout), a sincronização recupera o estado real.

### Sincronização manual

**Sincronizar status** consulta o envelope (`GET /envelopes/{id}`) e os eventos do documento (`GET .../documents/{id}/events`). O recurso de signatário da API 3.0 não tem status, então é pelos eventos `sign` que se sabe quem assinou. Serve para testar tudo localmente, sem URL pública. É **sempre acionada por um usuário**: a documentação da Clicksign proíbe polling automático, por isso não existe rotina agendada.

### Webhook

`POST /api/webhooks/clicksign`: fora do `auth:sanctum` (quem chama é a Clicksign), autenticado por **HMAC**, conforme "Segurança de Webhooks":

- header `Content-Hmac: sha256=<hex>`, com `<hex>` = HMAC-SHA256 do **corpo bruto** com o secret do webhook;
- comparação com `hash_equals` (tempo constante); sem secret configurado → **503**, HMAC ausente/inválido → **401**, e nada é processado;
- identifica o documento por `document.key` (o payload de webhook traz o documento, não o envelope);
- eventos tratados: `sign`, `refusal`, `auto_close`/`close`/`document_closed`, `cancel`, `deadline`; os demais recebem 200 e são ignorados;
- **idempotente**: cada fato tem uma chave (`signer_signed:{id}`, `signed`, `canceled`...) com índice único em `document_events`. Webhook repetido, ou webhook + sincronização do mesmo fato, gera **uma** linha no histórico;
- responde rápido: o processamento é só banco local, sem chamadas externas.

**Para ativar em homologação/produção**: publique o backend em uma URL **HTTPS** pública, cadastre `https://seu-dominio/api/webhooks/clicksign` no painel da Clicksign (Configurações → Webhooks) com os eventos acima, copie o **HMAC SHA256 Secret** gerado e coloque em `CLICKSIGN_WEBHOOK_SECRET` no `.env`. Localmente o webhook não chega sem túnel; use a sincronização manual.

### Download do documento assinado

React → Laravel → Clicksign → Laravel → usuário. O Laravel busca `links.files.signed` (URL temporária, pré-assinada, válida por ~5 minutos), baixa o arquivo **sem** enviar o Access Token (a autorização está na própria URL, e o token nunca vai para outro host), confere que é PDF e guarda uma cópia no disco privado. Os próximos downloads saem da cópia local. O navegador nunca recebe o link da Clicksign nem o token.

### Segurança

- Access Token e secret do webhook só no `.env`; o banco guarda apenas os IDs públicos dos recursos na Clicksign;
- `status` e IDs externos não são preenchíveis pelo front-end (fora do `$fillable`): o estado só muda pelo back-end;
- upload: MIME real (`mimetypes`), extensão (`mimes`), assinatura `%PDF-` nos primeiros bytes, 10 MB no máximo, nome no disco gerado (UUID), disco privado, caminho nunca exposto;
- **IDOR**: listagem e detalhe usam a mesma regra de visibilidade (`Document::visibleTo` + `DocumentPolicy`), e um id trocado na URL não mostra o documento de outro contexto;
- fotógrafo e editor não recebem e-mail/telefone dos signatários nem baixam os PDFs; os IDs da Clicksign aparecem só para o admin, numa área técnica;
- vínculos com pedido/agendamento são validados contra o cliente do documento.

### Contratos em Word (.docx)

O funcionário pode enviar **PDF** ou **Word (.docx)**. Com PDF, nada muda. Com Word, o backend guarda o `.docx` como **original**, converte uma cópia para PDF com o **LibreOffice headless** e é **esse PDF** que vai para a Clicksign. O Word nunca é enviado.

Três arquivos, três conceitos (colunas em `documents`):

| Conceito | Coluna | PDF enviado | Word enviado |
|---|---|---|---|
| Original | `original_file_path` + `original_file_type` | o PDF | o `.docx` |
| PDF para assinatura | `signing_file_path` | (vazio: é o próprio original) | o PDF convertido |
| Assinado | `signed_file_path` | vindo da Clicksign | vindo da Clicksign |

Os contratos antigos continuam como estavam: a migration só acrescenta colunas, com `original_file_type = 'pdf'` por padrão, sem mover nem converter arquivo nenhum.

- **Validação do upload**: aceita só `.pdf` e `.docx` (`.doc`, `.docm`, `.rtf` e `.odt` ficam de fora), até 10 MB. O conteúdo precisa bater com a extensão: um PDF precisa começar com `%PDF-`, e um DOCX precisa ter a estrutura Office Open XML do Word (`App\Support\DocxInspector`). São recusados ZIPs quaisquer, `.docm` renomeado, macros (`vbaProject.bin`), zip bombs e vínculos externos (imagens ou modelos na internet que o conversor tentaria buscar). Nada é extraído para o disco na inspeção.
- **Conversão** (`App\Services\DocumentConversionService`):
  - o executável vem só da configuração, e os argumentos vão como array (sem shell);
  - o nome original do arquivo nunca vira argumento;
  - cada conversão tem **diretório temporário e perfil do LibreOffice próprios** (`-env:UserInstallation`), com macros desativadas;
  - há timeout, e são conferidos o código de saída, a existência do PDF e a assinatura `%PDF-`;
  - o temporário é apagado sempre, mesmo em caso de erro.
- **Falhas**: se a conversão falhar (arquivo que não abre, tempo esgotado, LibreOffice ausente), o rascunho **fica salvo** com o motivo no histórico. O documento **não pode ser enviado** e nenhum envelope é criado. Nos detalhes, o botão **Converter novamente** tenta de novo sem reenviar o arquivo. PDF continua funcionando normalmente.
- **Revisão**: nos detalhes, a seção Arquivos mostra o original (Baixar original devolve o `.docx`) e o **PDF para assinatura**, com **Visualizar PDF** e **Baixar PDF**, para conferir fontes, margens e quebras de página antes de enviar.

#### Instalação do LibreOffice

O Composer **não** instala o LibreOffice: é um programa do sistema.

- **Windows (desenvolvimento)**: instale o LibreOffice (libreoffice.org) e aponte `DOCUMENT_CONVERTER_BINARY` para `C:\Program Files\LibreOffice\program\soffice.exe` no `backend/.env`.
- **Linux (servidor)**: `sudo apt install libreoffice-writer-nogui` (Debian/Ubuntu; só o Writer, sem interface gráfica) e `DOCUMENT_CONVERTER_BINARY=/usr/bin/soffice`.

Cuidados para o deploy Linux:

- **Fontes**: contratos feitos no Windows usam Calibri/Arial/Times. Sem essas fontes, o LibreOffice as substitui e a paginação muda. Instale `fonts-crosextra-carlito` (métrica de Calibri), `fonts-crosextra-caladea` (Cambria) e `fonts-liberation` (Arial/Times/Courier), e **sempre confira o PDF** antes de enviar;
- **Usuário e permissões**: o processo roda como o usuário do PHP (ex.: `www-data`), que precisa executar o `soffice` e escrever em `storage/framework/document-conversions` (ou em `DOCUMENT_CONVERTER_TEMP_PATH`). Nenhum `HOME` é necessário, porque o perfil é criado no temporário de cada conversão;
- **Recursos**: cada conversão abre um processo do LibreOffice (≈150–300 MB de RAM, alguns segundos de CPU). O timeout padrão é 90 s (`DOCUMENT_CONVERTER_TIMEOUT`). Com muitos envios simultâneos, a próxima evolução é mover a conversão para uma fila (`queue:work`) com poucos workers;
- **Armazenamento**: o `.docx`, o PDF convertido e o assinado ficam no armazenamento persistente do SnapManager (`SNAPMANAGER_FILESYSTEM_DISK`: disco privado local no desenvolvimento, bucket S3 privado em produção). Na conversão, o LibreOffice trabalha só com cópias temporárias, apagadas ao final. Ver [docs/armazenamento-s3.md](docs/armazenamento-s3.md).

### Ações e horários

- **Mesmas ações e nomes em todas as telas**: *Visualizar*, *Sincronizar status*, *Baixar original*, *Baixar assinado*. As regras por status ficam num lugar só (`documentActions()` em `utils/documents.js`), sempre a partir das `abilities` do back-end. Na tabela, "Baixar assinado" aparece em destaque quando o documento está finalizado, e as demais ações ficam no menu ⋯. Nos detalhes, "Sincronizar status" fica ao lado do badge e os downloads ficam na seção **Arquivos**.
- **Fuso horário**: o banco guarda UTC (`app.timezone`) e o navegador converte uma única vez para o horário local. Os horários da Clicksign chegam com offset (`2026-09-22T11:57:22-03:00`) e são convertidos para UTC antes de gravar (`DocumentSignatureService::parseTime`). Antes dessa correção, o offset era descartado pelo Eloquent e a "finalização" aparecia 3 horas antes. Registros gravados antes da correção podem ser recalculados a partir dos eventos da própria Clicksign com `php artisan documents:repair-signed-times`: ele só simula por padrão e grava apenas com `--apply` e confirmação.

### Documentos em Pedidos e Agenda

Os detalhes de um **pedido** e de um **agendamento** têm a seção **Documentos vinculados** (`LinkedDocumentsSection`, o mesmo componente nas duas telas). Ela não duplica o módulo:

- a lista vem do mesmo `GET /api/documents`, com os filtros `order_id` / `appointment_id`. A visibilidade por perfil (`Document::visibleTo`) é aplicada **antes** do filtro, então ver um pedido não dá acesso a documentos que o perfil não veria na aba Documentos. A lista traz só o necessário (sem PDF, sem histórico, sem contatos para perfis de leitura);
- cada item mostra título, tipo, status (os badges existentes), "X de Y assinaturas", envio e finalização. "Assinado parcialmente" nunca aparece como finalizado;
- ações por item, conforme `abilities` do back-end: **Ver detalhes** (abre o `DocumentDetailsModal` existente, com todas as ações dele), **Baixar original**, **Baixar assinado** e **Sincronizar**, pelos mesmos endpoints protegidos;
- **Novo documento** abre o `DocumentFormModal` existente com cliente, pedido e agendamento pré-selecionados. Título, tipo e PDF continuam com o usuário, e nada é enviado à Clicksign até a confirmação explícita;
- ações feitas na seção ou no modal de detalhes atualizam a lista na hora, sem polling.

### Limitações desta etapa

- **Somente PDF**: Word → PDF exigiria infraestrutura nova (LibreOffice/serviço de conversão) e fica para a próxima etapa;
- o webhook foi validado com payloads simulados nos testes. Com a Clicksign real ele depende de URL pública;
- `has_documentation` fica no padrão da Clicksign (pede CPF e data de nascimento na assinatura).

## Observação sobre soft delete

As entidades principais (`clients`, `appointments`, `orders`, `kanban_tasks`) usam **exclusão lógica** (soft delete): ao excluir um registro pela API, ele recebe a marca `deleted_at` e some das listagens normais, mas o dado continua no banco. Registros vinculados (ex.: um pedido cujo cliente foi excluído) continuam existindo normalmente, apenas exibindo o vínculo como ausente ("Cliente removido"). O histórico de status (`status_histories`) **não** é soft-deletable — é uma tabela de auditoria e permanece intacta mesmo se o pedido correspondente for excluído. Ainda não há tela de lixeira/restauração; a reversão de uma exclusão só é possível diretamente no banco de dados.

## Identidade visual

O layout foi inspirado esteticamente em plataformas de fotografia como o Pexels (interface limpa, cards refinados, boa hierarquia tipográfica) — **sem copiar** marca, logotipo, textos ou layout exato de nenhuma referência. Cantos de enquadramento inspirados em visor de câmera continuam construídos só com CSS.

**Tipografia**: **Manrope** (corpo de texto, botões, inputs, tabelas — `--font-sans`) + **Plus Jakarta Sans** (títulos — `--font-heading`), auto-hospedadas via `@fontsource` (sem link externo para o Google Fonts em tempo de execução). `--font-serif` (Georgia) continua reservada só pro toque editorial da hero de login, sem relação com essa troca.

**Tema claro/escuro**: alternável pelo botão de sol/lua no Header (topo das telas internas) e também no cabeçalho da galeria pública. A preferência é salva em `localStorage` (`snapmanager_theme`) e, na ausência de uma escolha salva, o tema inicial segue `prefers-color-scheme` do sistema operacional. Um script inline no `index.html` aplica o atributo `data-theme` no `<html>` antes do React montar, evitando o "flash" de tema errado no primeiro render. Toda a paleta é feita de variáveis CSS (`--color-*`, `--shadow-*`) redefinidas em `:root[data-theme='dark']` — nenhum CSS Module foi duplicado por tema. A Sidebar e o hero da tela de login/galeria pública mantêm o fundo escuro fixo (identidade de marca), independente do tema escolhido.

As imagens/vídeo locais do projeto são arte própria fornecida pelo autor (não são assets de terceiros):
- `frontend/src/assets/logo-lockup-light.png` — versão clara da logo (ícone + "SnapManager"), usada nos fundos escuros da aplicação (Sidebar, telas de login/cadastro/recuperação de senha, galeria pública). Os arquivos originais (fundo transparente e fundo branco, ambos com texto em preto/laranja) ficam em `logo/` na raiz do repositório como referência para versões futuras em fundo claro. O favicon (`frontend/public/favicon.png`) é um recorte só do ícone, sobre um fundo laranja arredondado, gerado a partir do mesmo material.
- `frontend/src/assets/login-hero.mp4` (+ `login-hero-poster.jpg`) — reel de fotos em loop no painel escuro das telas de login/cadastro/recuperação de senha, com um véu escuro (gradiente CSS) por cima para o texto continuar legível. Recomprimido de ~3,3MB para ~118KB (H.264, `moov atom` no início do arquivo — "faststart", necessário para o `<video>` do navegador tocar via streaming progressivo) e com um poster estático para não haver flash em branco antes do vídeo carregar. Pausa automaticamente para quem tem "reduzir movimento" ativado no sistema.

## Observação sobre Atomic Design

O frontend segue Atomic Design, organizado em `frontend/src/components/`:

```
atoms/        Button, Input, Textarea, Select, Badge, Card, Spinner, Modal, Table, Icon,
              EmptyState, Checkbox, ImageThumb, ThemeToggle
molecules/    FormField, SummaryCard, AlertItem, SidebarLink, Pagination, ClientPicker,
              CopyLinkButton, ImageSelectionCard, DashboardReportActions, ChartCard
organisms/    Sidebar, Header, LoginForm, RegisterForm, ForgotPasswordForm, ResetPasswordForm,
              DashboardHero, DashboardSummary, DashboardCharts, OrdersStatusChart, RevenueChart,
              AppointmentsChart, AlertsPanel, *Toolbar, *Table, *FormModal, *DetailsModal,
              Delete*Dialog, UpdateOrderStatusModal, CloseGalleryDialog, GalleryUploadModal,
              GalleryImagesGrid, PublicGalleryGrid
templates/    AuthTemplate, AdminTemplate
pages/        Login, Register, ForgotPassword, ResetPassword, GoogleCallback, Dashboard,
              Clients, Appointments, Orders, Galleries, PublicGallery, NotFound
```

Cada componente vive em sua própria pasta com `.jsx`, `.module.css` (CSS Modules, escopado por componente — sem folha de estilo global solta) e um `index.js` de barrel export. Componentes de uso restrito a um único formulário (como o seletor de agendamento dentro do formulário de pedido) ficam co-localizados dentro do organismo que os usa, sem virar um módulo separado.

## Comandos úteis

```bash
# Backend
php artisan migrate:fresh --seed   # recria o banco com dados de exemplo
php artisan serve --port=8000      # sobe a API
php artisan test                   # roda a suíte de testes
php artisan galleries:expire       # marca como "expired" galerias ativas com expires_at no passado
                                    # (ainda não agendado automaticamente — rode manualmente ou
                                    # agende via routes/console.php no futuro)

# Frontend
npm run dev                        # ambiente de desenvolvimento
npm run build                      # build de produção
```
