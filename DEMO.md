# Roteiro de demonstração — SnapManager

Este arquivo reúne os passos manuais para demonstrar as funcionalidades mais recentes do sistema (galeria de fotos com marca d'água, CEP, validação de CPF, cadastro de usuário, recuperação de senha), incluindo o que não é coberto automaticamente pelo seeder.

## Pré-requisitos

```bash
cd backend
php artisan migrate:fresh --seed
php artisan serve --port=8000
```

```bash
cd frontend
npm run dev
```

Login de teste: `admin@snapmanager.test` / `password` (veja mais opções no README).

## Autenticação, autorização por perfil e Enums (nova etapa)

### Login e Sanctum (autenticação)

1. Acesse `/login` e entre com `admin@snapmanager.test` / `password`. O Laravel Sanctum emite um token Bearer, guardado em `localStorage` (`snapmanager_token`) e enviado em todo request seguinte.
2. Abra o DevTools → Application → Local Storage e mostre o token salvo, ou a aba Network de qualquer chamada à API e mostre o header `Authorization: Bearer ...`.
3. Delete o token do `localStorage` e recarregue a página — a próxima chamada à API recebe 401 e o front-end redireciona sozinho para `/login`.

### Autorização por perfil (o ponto principal)

4. Faça logout e entre como `fotografo@snapmanager.test` / `password`. Note que o menu lateral já não mostra "Clientes", "Agenda" nem "Dashboard" — só "Pedidos" e "Galerias", os únicos módulos que esse perfil acessa.
5. Tente digitar `http://localhost:5173/clientes` direto na URL — o sistema redireciona sozinho para "Pedidos" (o front-end também bloqueia, não só esconde o link).
6. Para provar que a autorização real está no back-end (não só escondida no React), abra o DevTools e rode, com o fotógrafo logado:
   ```js
   fetch('http://localhost:8000/api/clients', {
     headers: { Authorization: `Bearer ${localStorage.getItem('snapmanager_token')}`, Accept: 'application/json' },
   }).then((r) => r.json()).then(console.log);
   ```
   A resposta é **403** com a mensagem "Você não possui permissão para executar esta ação." — mesmo chamando a API direto, sem passar pela tela.
7. Entre como `editor@snapmanager.test` — repare que esse perfil só vê "Pedidos" e "Galerias" também, mas sem poder criar/editar nada neles (só alterar o status/etapa). Entre como `atendimento@snapmanager.test` — esse já vê Clientes/Agenda/Pedidos e consegue criar registros, mas o botão de excluir pedido e as ações de gerenciar galeria (enviar fotos, editar, encerrar, excluir) somem — só o `admin@snapmanager.test` tem acesso a elas.

### Enum no código

8. Abra `backend/app/Enums/OrderStatus.php` — mostre os `case`s (`Pending = 'pending'`, etc.) e o método `label()`. Depois abra `backend/app/Models/Order.php` e mostre o cast (`'status' => OrderStatus::class`) e `backend/app/Http/Requests/StoreOrderRequest.php` (`Rule::enum(OrderStatus::class)`).
9. Para provar que o banco continua com texto simples (não ENUM nativo do Postgres):
   ```bash
   php artisan tinker --execute="echo App\Models\Order::first()->getAttributes()['status'];"
   ```
   Isso imprime a string bruta (`"pending"`), enquanto `Order::first()->status` (sem `getAttributes()`) já devolve a instância do enum — a tradução acontece só na camada de aplicação.
10. Teste a validação: tente `PATCH /api/orders/{id}/status` com `{"status": "isso_nao_existe"}` (autenticado como admin) — a API responde 422.

## Gráficos do Dashboard

11. No Dashboard (logado como admin ou atendimento), role até os gráficos abaixo dos cards de resumo: **Faturamento — últimos 30 dias** (área, um valor por dia), **Pedidos por status** (barras, reaproveitando as mesmas cores dos badges da tela de Pedidos) e **Agendamentos — próximos 7 dias** (barras).
12. Passe o mouse sobre qualquer barra/ponto — aparece um tooltip com o valor exato. Todos os dados vêm agregados do banco (`GET /api/dashboard/charts`, com `GROUP BY`/`SUM`/`COUNT` no Postgres) — o React nunca soma nada sozinho.
13. Alterne entre tema claro e escuro (botão no Header) — os gráficos se adaptam automaticamente, incluindo os tooltips.

## Kanban de produção

O `KanbanSeeder` já cria 5 colunas (Agendado, A fazer, Em andamento, Em revisão, Concluído) e 3 cards — um vindo de agendamento, um de pedido e um manual — para o quadro não abrir vazio.

1. Logado como `admin@snapmanager.test`, clique em **Produção** no menu lateral. O quadro aparece com as colunas em ordem e os cards dentro delas. Repare na etiqueta de origem de cada card: **Agendamento**, **Pedido** ou **Manual**.
2. **Arraste um card** de "A fazer" para "Em andamento". Ele muda de lugar na hora — a chamada à API acontece depois, em segundo plano. Arraste outro card para uma posição específica no meio de uma coluna.
3. **Recarregue a página (F5)**. As colunas e os cards voltam exatamente nas posições em que foram deixados: a ordem está gravada na coluna `position` da tabela `kanban_tasks`, não no navegador.
4. **Importe um agendamento**: vá em **Agenda**, encontre uma linha e clique no ícone de quadro (colunas) nas ações. Aparece o aviso "foi adicionado ao quadro de Produção" com o atalho **Ver no quadro**. Clique nele e confirme que o card novo está lá, já com cliente, prazo e o fotógrafo responsável copiados do agendamento.
5. **Tente importar o mesmo agendamento de novo** — o aviso agora diz "já está no quadro de Produção", e nenhum card duplicado é criado. A garantia não é só a mensagem: existe um índice único parcial no banco (`kanban_tasks_appointment_unique`). Repita o teste em **Pedidos**, com o mesmo ícone.
6. **Crie uma demanda avulsa**: no quadro, clique em **Nova demanda** (ou no `+` do cabeçalho de uma coluna), preencha título, responsável, prioridade e prazo. O card nasce com origem **Manual** — sem agendamento nem pedido por trás.
7. **Coluna de conclusão**: arraste um card para "Concluído". O card ganha o ícone de concluído; abra-o e veja a data em "Conclusão". Arraste de volta para "Em andamento" — a data some. Isso é a flag `is_done` da coluna, não um campo que alguém preencheu à mão.
8. **Mover card ≠ mudar status do pedido**: abra o card **"Retratos corporativos - equipe"** (origem **Pedido**) e anote o status do pedido mostrado no bloco "Registro de origem" — repare que ele já é diferente do nome da coluna onde o card está. Mova o card entre colunas, reabra e confirme: o status do pedido não mudou. Faça o caminho inverso em **Pedidos** (alterar o status por lá) e volte ao quadro — o card continua onde estava. As duas regras são deliberadamente separadas nesta versão, e o próprio modal do card avisa isso.
9. **Filtros**: use "Minhas tarefas" (atalho para `assigned_to=me`), e os seletores de responsável, prioridade e origem. Abra o DevTools → Network e veja que os filtros vão na **query string** (`/api/kanban?origin=order&priority=high`) — quem filtra é o Postgres, não o React.
10. **Colunas são dados**: clique em **Nova coluna**, crie "Aguardando cliente", renomeie com o lápis, mova com as setas ◀ ▶ e recarregue a página para ver a nova ordem persistida. Depois tente **excluir** uma coluna que tenha cards: o sistema exige escolher para onde os cards vão — nenhuma tarefa é apagada em silêncio.
11. **Autorização real (não só botão escondido)**: entre como `fotografo@snapmanager.test`. Ele vê o quadro, mas não tem os botões de coluna nem o de importar, e só consegue arrastar os cards atribuídos a ele. Para provar que o bloqueio está no back-end, rode no DevTools com o fotógrafo logado:

```js
await fetch('http://localhost:8000/api/kanban/columns', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    Authorization: 'Bearer ' + localStorage.getItem('snapmanager_token'),
  },
  body: JSON.stringify({ name: 'Coluna proibida' }),
}).then((r) => r.status); // 403
```

Tente também mover um card de outra pessoa via `PATCH /api/kanban/tasks/{id}/move` — **403**, vindo da `KanbanTaskPolicy`.

## Documentos e Assinaturas (Clicksign Sandbox)

Pré-requisito: `CLICKSIGN_ACCESS_TOKEN` do **Sandbox** no `backend/.env` (confira com `php artisan clicksign:check`). Use como signatário um e-mail ou WhatsApp **seu**: o Sandbox envia convites de verdade.

1. Logado como `admin@snapmanager.test` ou `atendimento@snapmanager.test`, clique em **Documentos** no menu lateral.
2. Clique em **Novo documento** e preencha o título (ex.: "Contrato de ensaio — teste") e o tipo.
3. **Selecione o cliente**. Os campos de pedido e agendamento passam a listar só os registros desse cliente.
4. **Carregue o PDF** (até 10 MB). Um arquivo que não seja PDF de verdade é recusado, mesmo com extensão `.pdf`.
5. Em **Signatários**, adicione um signatário com **nome e sobrenome**.
6. **Escolha o canal**: *E-mail* pede e-mail, *WhatsApp* pede telefone (DDD + número). Salve: o documento aparece como **Rascunho** e nada foi enviado ainda.
7. Nos detalhes, clique em **Enviar para assinatura**, revise e confirme. O envelope é criado e ativado na Clicksign, e o status vira **Aguardando assinatura**. Um aviso lembra que a solicitação ainda não saiu.
8. Clique em **Enviar solicitação** e confirme. O histórico registra o envio, o botão vira **Reenviar solicitação**, e um segundo clique imediato é bloqueado.
9. **Assine fora do sistema**: abra o convite recebido por e-mail/WhatsApp e conclua a assinatura na Clicksign.
10. Volte aos detalhes e clique em **Sincronizar status** (ou, com o webhook cadastrado numa URL pública, aguarde a atualização automática). Com dois signatários, depois da primeira assinatura o badge mostra **"1 de 2 assinaturas"**.
11. Quando a Clicksign finalizar o envelope, o status vira **Assinado**, e o histórico mostra "Signatário assinou" e "Documento finalizado", sem duplicatas mesmo sincronizando de novo.
12. Clique em **Baixar documento assinado**. O PDF vem pelo Laravel; o navegador não recebe o link da Clicksign nem o token.

**Autorização (backend, não só botão escondido)**: entre como `fotografo@snapmanager.test`. Ele vê a lista e os detalhes, mas sem ações, sem os contatos dos signatários e sem download. No DevTools:

```js
await fetch('http://localhost:8000/api/documents/1/send', {
  method: 'POST',
  headers: { Accept: 'application/json', Authorization: 'Bearer ' + localStorage.getItem('snapmanager_token') },
}).then((r) => r.status); // 403
```

Como `editor@snapmanager.test`, só aparecem documentos vinculados a um pedido, e abrir o id de outro documento pela API dá **403**.

### Contrato em Word (.docx)

Pré-requisito: LibreOffice instalado e `DOCUMENT_CONVERTER_BINARY` configurado (ver README → "Instalação do LibreOffice"). Sem ele, o passo 3 mostra o aviso de conversão pendente, o que também serve para demonstrar o tratamento de falha.

1. Crie no Word um contrato simples com título, alguns parágrafos, uma tabela e uma linha de assinatura. Salve como **.docx**.
2. Em **Documentos → Novo documento**, escolha o arquivo: aparece "Word · será convertido para PDF ao salvar". Preencha o resto e salve (o botão mostra "Salvando e convertendo...").
3. Nos detalhes, a seção **Arquivos** mostra o **Original · Word** e o **PDF para assinatura · Conversão concluída**.
4. Clique em **Visualizar PDF** e confira fontes, tabela, margens e quebras de página. **Baixar original** devolve o `.docx`, e **Baixar PDF** devolve o convertido.
5. Envie para assinatura: a confirmação diz "Arquivo que será assinado: ….pdf (PDF convertido do Word)". Siga o fluxo de sempre (solicitação, assinatura, sincronizar).
6. Ao final, **Baixar assinado** devolve o PDF assinado da Clicksign, e o `.docx` original continua disponível.
7. Teste também um arquivo `.docm` ou um PDF renomeado para `.docx`: os dois são recusados com mensagem clara.

### Documentos dentro de Pedidos e Agenda

1. Em **Pedidos**, abra os detalhes (ícone de olho) de um pedido sem documentos: a seção **Documentos vinculados** mostra "Nenhum documento vinculado a este pedido."
2. Clique em **Novo documento** na seção. O formulário abre com o **cliente, o pedido (e o agendamento do pedido, se houver) já selecionados**. Preencha título, tipo e PDF e salve: o documento aparece na lista da seção como **Rascunho**, e os detalhes dele abrem por cima do pedido.
3. Crie um segundo documento para o mesmo pedido (ex.: "Autorização de uso de imagem") e confira que os dois aparecem.
4. Envie um deles para assinatura pelos detalhes e volte ao pedido: o status já está atualizado na seção, sem recarregar a página.
5. Com um documento de dois signatários e só um assinado, clique em **Sincronizar** na seção: aparece "Assinado parcialmente" e **"1 de 2 assinaturas"**, sem botão "Baixar assinado".
6. Em **Agenda**, abra um agendamento: a mesma seção lista só os documentos daquele agendamento, e **Novo documento** vem com cliente e agendamento pré-selecionados.
7. Pressione **Esc** com os detalhes de um documento abertos por cima do pedido: fecha só o documento, e o pedido continua aberto.
8. Como `fotografo@snapmanager.test`, abra o mesmo agendamento: a seção aparece em modo leitura (sem "Novo documento", sem download, sem sincronizar). Como `editor@snapmanager.test`, o pedido mostra a seção nas mesmas condições.

## Galeria de fotos

O seeder **não** popula galerias com imagens reais (evita versionar binários e manter o repositório leve). Siga este roteiro manual:

1. Faça login no painel e acesse **Galerias** no menu lateral.
2. Clique em **Nova galeria**, selecione um cliente (ex.: "João Oliveira"), preencha um título (ex.: "Ensaio de casamento") e, opcionalmente, uma data de expiração e um pedido relacionado. Salve.
3. Na listagem, clique no ícone de upload (nuvem) da galeria criada.
4. Selecione de 5 a 10 fotos de exemplo do seu computador (jpg, png ou webp, até 10MB cada) e clique em **Enviar**. As miniaturas aparecem após o upload.
5. Clique no ícone de link (copiar) na linha da galeria — o link público é copiado para a área de transferência.
6. Abra o link copiado em uma aba anônima/privada (simula o cliente, sem login): `http://localhost:5173/public/gallery/<token>`.
7. Na página pública, cada foto mostra sua numeração ("Foto 001", "Foto 002"...) no canto — clique em algumas fotos para selecioná-las (fica destacado com borda azul e check) e clique em **Confirmar seleção**. Aparece a mensagem "Seleção enviada com sucesso!".
8. Volte ao painel interno (listagem de **Galerias**) — a galeria agora mostra o badge **"Escolha concluída"** na coluna "Escolha", e a linha fica com um fundo verde suave, destacando que já pode ser conferida.
9. Clique no ícone de olho (ver detalhes) da mesma galeria — no topo aparece um bloco de resumo com "X de Y fotos selecionadas", o badge de status da escolha e a data/hora da confirmação. As fotos escolhidas pelo cliente aparecem marcadas com um selo verde, e a numeração de cada foto permite identificar exatamente qual é qual ao conversar com o cliente.
10. Para testar o encerramento: no painel, clique no ícone de cadeado para **encerrar** a galeria e tente acessar o link público novamente — a seleção fica bloqueada com uma mensagem amigável, mas as fotos continuam visíveis.
11. Para testar a expiração automática: edite uma galeria e defina "Expira em" para uma data/hora no passado, depois rode:
    ```bash
    php artisan galleries:expire
    ```
    A galeria passa a aparecer como "Expirada" na listagem e o link público bloqueia novas seleções. (O comando ainda não está agendado automaticamente — ver seção "Pendências técnicas" no relatório final.)

### Testando validações de upload (opcional)

- Tente enviar um arquivo `.txt` renomeado para `.jpg`: a API rejeita por MIME type real, não apenas pela extensão.
- Tente enviar uma imagem maior que 10MB: a API rejeita por tamanho.

## Calendário único (todos os módulos)

O mesmo calendário do SnapManager aparece em todos os formulários com data. Confira em cada um:

1. **Agenda → Novo agendamento**: clique em **Início**, escolha dia e horário e clique em **Concluir**. Depois abra **Término**: os dias anteriores ao início aparecem desabilitados. Salve e confira na listagem que o horário mostrado é o mesmo que você escolheu.
2. **Agenda → editar** um agendamento existente: as datas aparecem preenchidas como estavam; salve outro campo (ex.: local) sem tocar nelas e confira que os horários não mudaram.
3. **Pedidos → Novo pedido → Prazo de entrega**: o calendário abre **sem** seleção de horário (é só data) e fecha ao escolher o dia. Datas passadas ficam desabilitadas.
4. **Pedidos → filtro "Prazo de entrega"** (na barra de filtros): escolha uma data e confira que a lista filtra igual a antes; limpe com o "x".
5. **Documentos → Novo documento → Prazo para assinatura**: só data, com hoje e os dias além de 90 dias desabilitados.
6. **Produção → Nova demanda → Prazo**: só data.
7. **Galerias → Nova galeria → Expira em**: continua com data **e** horário, com o atalho **Expirar em 48 horas**.
8. Em qualquer um deles: abra o calendário e pressione **Esc** — fecha só o calendário, e o formulário continua aberto. Clique dentro do calendário e confirme que o modal não fecha.
9. Role o formulário com o calendário aberto perto do rodapé: ele deve abrir para cima quando não houver espaço embaixo, sem ser cortado.
10. Repita um dos formulários no **tema escuro** e numa **janela estreita** (celular), conferindo que o calendário cabe na tela sem rolagem horizontal.

## Validade e compartilhamento da galeria

1. Em **Galerias → Nova galeria**, clique no campo **Expira em**: abre o calendário do SnapManager. Confira o dia de hoje com contorno, navegue entre os meses pelas setas e escolha um dia — ele fica preenchido.
2. Ajuste o **Horário** no mesmo painel (campo de hora ou um dos atalhos 09:00 / 12:00 / 18:00 / 23:59) e clique em **Concluir**. O botão mostra algo como "25/10/2026 às 18:00".
3. Clique em **Expirar em 48 horas**: o campo é preenchido com a data e a hora de agora + 48 h. Troque a data manualmente depois, para conferir que o atalho não trava nada.
4. Salve e reabra a galeria para editar: a data volta exatamente como foi gravada. Confira em **Detalhes** que "Expira em" mostra o mesmo horário que você escolheu (não três horas antes).
5. Tente escolher um dia anterior a hoje: os dias passados aparecem desabilitados no calendário, e o back-end continua recusando datas no passado.
6. Abra os **detalhes** de uma galeria e localize o bloco do link público: ao lado de **Copiar link público** está **Enviar por WhatsApp**.
7. Com um cliente que tenha telefone cadastrado, clique no botão: o WhatsApp abre **na conversa desse cliente**, com a mensagem pronta e o link da galeria. **Confira a mensagem e envie você mesmo** — nada é enviado sozinho.
8. Repita com um cliente **sem** telefone: aparece o aviso de que você escolherá o contato, e o WhatsApp abre só com a mensagem pronta.
9. Alterne entre tema claro e escuro com o calendário aberto e confira a legibilidade; repita numa janela estreita (celular).

## SnapSwipe (seleção por gestos)

Use o mesmo link público da galeria. Vale testar no celular **e** no computador — os gestos não foram verificados em navegador por quem implementou.

1. Abra o link público. Com fotos na galeria, aparece o seletor **Galeria | SnapSwipe** abaixo do título.
2. Toque em **SnapSwipe**: aparece um cartão por vez, com "Foto 1 de N", contagem de selecionadas e barra de progresso.
3. **Arraste o cartão para a direita** sem soltar: o selo verde "✓ Selecionar" aparece aos poucos. Arraste para a esquerda: aparece "✕ Passar". Solte no meio do caminho (arrasto pequeno): o cartão volta e **nada** é decidido.
4. Deslize para a direita para selecionar e para a esquerda para passar algumas fotos.
5. Toque em **Desfazer**: volta à última foto, com a decisão dela desfeita; as outras continuam como estavam.
6. Toque em **Ampliar** (ou toque simples no cartão): abre a foto grande com marca d'água. Feche e confira que voltou **ao mesmo cartão**, sem ter selecionado nada por acidente.
7. Volte para **Galeria**: as fotos escolhidas no SnapSwipe aparecem marcadas. Desmarque uma na grade, volte ao SnapSwipe e confira na revisão que ela saiu da seleção.
8. No **computador**: arraste o cartão com o mouse e use as setas <kbd>←</kbd> <kbd>→</kbd>; <kbd>U</kbd> desfaz. Navegue só pelo teclado (Tab) e confira que o foco fica visível.
9. Vá até o fim: aparece o resumo com fotos avaliadas e selecionadas. Confirme que **nada foi finalizado sozinho**.
10. Em **Revisar selecionadas**, troque alguma seleção e use o botão de voltar ao cartão de uma foto específica.
11. Toque em **Finalizar seleção** e confirme que a mensagem de sucesso aparece — é o mesmo envio da galeria tradicional.
12. **Rolagem**: numa galeria com muitas fotos, role a página com o dedo começando em cima do cartão: a página deve rolar normalmente, sem decidir a foto.
13. Ative "reduzir movimento" no sistema e confira que as animações somem, mas tudo continua funcionando.
14. Teste com uma galeria **expirada ou encerrada**: o SnapSwipe mostra as fotos em modo leitura, sem botões de decisão.

## Marca d'água nas fotos da galeria

Toda galeria nova já nasce com marca d'água de texto ativada por padrão ("PROIBIDO REPRODUÇÃO"). O cliente sempre vê a versão com marca d'água no link público; o painel interno sempre mostra a foto original, sem marca.

1. Ao criar uma galeria, a caixa "Aplicar marca d'água nas fotos da galeria" já vem marcada, com o tipo "Texto genérico" e o texto padrão. Faça upload de algumas fotos normalmente.
2. Abra o link público da galeria — as fotos aparecem com o texto em diagonal, repetido, semi-transparente.
3. Volte ao painel, clique no ícone de olho (detalhes) — a caixa "Marca d'água: Ativa" aparece, com o tipo e o texto configurado. As fotos mostradas aqui **não** têm marca d'água (é a versão original, para o funcionário conferir qualidade).
4. Para testar a marca d'água personalizada: crie outra galeria, em "Tipo de marca d'água" escolha "Imagem personalizada" e salve. Abra os detalhes da galeria criada e clique em "Enviar imagem" para escolher um PNG/JPG/WEBP (idealmente um PNG com fundo transparente). Faça upload de uma foto **depois** de enviar a imagem de marca d'água — só fotos enviadas a partir desse momento usam a nova imagem (não há reprocessamento retroativo, ver limitações no relatório final).
5. Para desativar: desmarque a caixa ao editar a galeria — fotos enviadas depois disso não recebem marca d'água (`has_watermark: false`).

## Endereço com preenchimento automático por CEP (ViaCEP)

O endereço do cliente agora é estruturado (CEP, rua, número, complemento, bairro, cidade, UF) em vez de um campo de texto único.

1. Acesse **Clientes** → **Novo cliente** (ou edite um cliente existente).
2. Digite um CEP válido no campo correspondente (ex.: `01310-100`). Ao completar os 8 dígitos, a busca acontece automaticamente — aparece "Consultando CEP..." e, em seguida, rua/bairro/cidade/UF são preenchidos.
3. Número e complemento continuam manuais; todos os campos preenchidos automaticamente podem ser editados livremente antes de salvar.
4. Para testar os erros: digite um CEP com menos de 8 dígitos (mensagem "CEP inválido"), ou um CEP inexistente como `00000-000` (mensagem "CEP não encontrado").

A consulta passa pelo back-end (`GET /api/cep/{cep}`, autenticado), que por sua vez chama o ViaCEP — o front-end nunca chama o ViaCEP diretamente.

## Validação de CPF

O campo "Documento (CPF/CNPJ)" agora valida CPF de verdade (cálculo dos dígitos verificadores), tanto no front quanto no back-end.

1. No formulário de cliente, digite um CPF com os 9 primeiros dígitos (ex.: `111.444.777`) — aparece "Dígitos verificadores esperados: 35".
2. Complete com um CPF válido (ex.: `111.444.777-35`) — aparece "CPF válido." em verde.
3. Digite um CPF com dígitos verificadores errados (ex.: `111.444.777-00`) — aparece "CPF inválido." em vermelho, e o salvamento é bloqueado tanto no front quanto (se forçado via API) no back-end.
4. Digite um CPF com todos os dígitos iguais (ex.: `111.111.111-11`) — também é rejeitado.
5. Um CNPJ (14 dígitos) continua sendo aceito sem validação de dígito verificador (fora do escopo desta etapa).

Os clientes de exemplo do seeder já usam CPFs fictícios porém válidos (antes usavam sequências repetidas, que passaram a ser rejeitadas pela nova validação).

## Bloqueio de datas passadas

Formulários de agendamento, pedido e galeria não aceitam mais datas anteriores a hoje ao criar ou definir uma **nova** data.

1. Tente criar um agendamento com data/hora no passado — o campo já vem com `min` bloqueando visualmente, e o back-end rejeita com "A data do agendamento não pode ser anterior à data atual." se forçado via API.
2. O mesmo vale para o prazo de entrega de um pedido e a expiração de uma galeria.
3. **Importante**: pedidos/agendamentos/galerias que já estão com data no passado (ex.: um pedido atrasado, usado para o alerta de atraso do dashboard) continuam **editáveis normalmente** nos outros campos — a validação só entra em ação quando a data está sendo efetivamente alterada para um novo valor. Os dados de exemplo do seeder (que incluem um pedido propositalmente atrasado, para o alerta funcionar) não são afetados, porque os seeders criam os registros direto no banco, sem passar pela validação dos formulários.

## Cadastro de usuário

1. Na tela de login (`/login`), clique em **Criar conta**.
2. Preencha nome, e-mail (não pode já existir), senha (mínimo 8 caracteres) e confirmação de senha.
3. Ao salvar, você é autenticado automaticamente (recebe um token e é redirecionado para o dashboard) com o papel `attendant`.
4. Para testar validações: tente cadastrar com o mesmo e-mail duas vezes (erro "e-mail já está em uso"), ou com senha e confirmação diferentes.

Não há painel de administração de usuários nesta etapa — a promoção de papel (ex.: para `admin`) só pode ser feita diretamente no banco.

## Recuperação de senha

Em ambiente local não há envio de e-mail real (`MAIL_MAILER=log`): o corpo do e-mail (com o link de redefinição) é gravado em `backend/storage/logs/laravel.log`.

1. Na tela de login, clique em **Esqueci minha senha**.
2. Informe o e-mail (ex.: `admin@snapmanager.test`) e envie. A tela mostra uma mensagem de confirmação genérica — o mesmo texto aparece mesmo se o e-mail não existir no sistema (evita revelar quais e-mails têm conta).
3. Abra `backend/storage/logs/laravel.log` e procure pela ocorrência mais recente de `reset-password?token=`. Copie a URL completa (algo como `http://localhost:5173/reset-password?token=...&email=...`).
4. Cole a URL no navegador. A tela pede a nova senha (mínimo 8 caracteres) e a confirmação.
5. Após redefinir, você é redirecionado para o login — entre com a nova senha para confirmar.
6. Para testar o token inválido: tente acessar `/reset-password` sem `token`/`email` na URL (mensagem de link inválido), ou reenvie a mesma URL de redefinição depois de já ter usado — o token já foi consumido e a API responde com "token inválido ou expirado".

Dica: no Windows/PowerShell, para ver rapidamente as últimas linhas do log:
```powershell
Get-Content backend/storage/logs/laravel.log -Tail 80 | Select-String "reset-password"
```

## Login com Google

A estrutura está pronta (rotas, Socialite instalado, find-or-create por e-mail, validação de `state`), mas **não há credenciais reais configuradas** neste ambiente — por isso o botão "Entrar com Google" fica automaticamente oculto na tela de login (o front-end consulta `GET /api/auth/google/status` e só mostra o botão se `available: true`).

Para habilitar de verdade:

1. Crie um "OAuth 2.0 Client ID" do tipo "Web application" no [Google Cloud Console](https://console.cloud.google.com/apis/credentials).
2. Nas "Authorized redirect URIs", adicione: `http://localhost:8000/auth/google/callback`.
3. Preencha no `backend/.env`:
   ```env
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
   ```
4. Reinicie o backend. O botão "Entrar com Google" passa a aparecer na tela de login automaticamente.

## Escolha concluída, numeração e performance (nova etapa)

### Aviso de escolha concluída

Já demonstrado no fluxo da galeria acima (passos 7-9): a galeria mostra "Aguardando escolha" (cinza) até o cliente confirmar pela primeira vez, e passa para "Escolha concluída" (verde) a partir daí — inclusive se o cliente voltar e mudar a seleção depois, a data/hora só avança para a confirmação mais recente. Isso só é bloqueado se a galeria estiver expirada ou encerrada.

### Numeração das fotos

Cada foto ganha um número sequencial ("Foto 001", "Foto 002"...) baseado na posição real dentro da galeria — não trava se uma foto do meio for excluída (a numeração se ajusta sem buracos). Aparece nas fotos da página pública e na grade interna de detalhes.

### Fonte

Trocamos a fonte padrão do sistema por **Manrope** (corpo de texto, botões, inputs, tabelas) + **Plus Jakarta Sans** (títulos), auto-hospedadas via `@fontsource` — sem chamada para o Google Fonts em tempo de execução. Para ver a diferença, compare qualquer título de página (ex.: "Galerias") com o texto normal ao lado.

### Performance

- **Divisão de código por rota**: cada página interna (Clientes, Agenda, Pedidos, Galerias) só é baixada quando você navega até ela — o bundle inicial da tela de login caiu de ~397KB para ~302KB (medido com `npm run build`).
- **Carregamento das miniaturas da galeria só quando visíveis**: numa galeria com muitas fotos, abra os detalhes e observe (na aba Network do navegador) que as requisições das miniaturas vão disparando conforme você rola a tela, não todas de uma vez.
- **Skeleton de carregamento**: as miniaturas mostram um efeito de "brilho" enquanto carregam, em vez de um espaço em branco.

Para conferir o tamanho dos bundles a qualquer momento:
```bash
cd frontend
npm run build
```

## Tema claro/escuro

1. Em qualquer tela interna, clique no ícone de sol/lua no canto superior direito do cabeçalho — o sistema inteiro alterna entre tema claro e escuro instantaneamente (cards, tabelas, modais, badges, inputs).
2. Recarregue a página (F5): o tema escolhido continua o mesmo (fica salvo em `localStorage`).
3. Abra o link público de uma galeria — o mesmo botão aparece no topo direito do cabeçalho escuro da página; ele também respeita e salva a preferência.
4. Para testar a detecção automática: limpe o `localStorage` do site (DevTools → Application → Local Storage → remover `snapmanager_theme`) e recarregue — o tema inicial passa a seguir a preferência do sistema operacional (dark mode do Windows/macOS).

## Emissão de relatório do Dashboard

1. Acesse o **Dashboard**. No card de destaque no topo (com a frase resumindo o dia), clique em **Emitir relatório**.
2. O botão mostra "Gerando relatório..." enquanto a requisição está em andamento.
3. Ao concluir, um PDF (`relatorio-operacional-snapmanager-AAAA-MM-DD.pdf`) é baixado automaticamente pelo navegador, e aparece "Relatório gerado com sucesso." abaixo do botão.
4. Abra o PDF: ele traz cabeçalho com data/hora de emissão e usuário logado, cards de resumo (clientes, agendamentos, pedidos, financeiro, galerias), uma tabela de pedidos por status, um resumo de galerias (ativas/expiradas/encerradas/aguardando escolha/concluídas) e os 5 alertas operacionais mais relevantes no momento.
5. Para testar o filtro de período, chame a API diretamente com `start_date`/`end_date` (ex.: `GET /api/dashboard/report?start_date=2026-01-01&end_date=2026-12-31`, com o header `Authorization: Bearer <token>`) — o relatório passa a mostrar "Período analisado" com as datas informadas, e os agendamentos/pedidos "no período" são recalculados; indicadores de estado atual (atrasados, prontos para entrega, status de galeria) continuam refletindo a operação agora, não o período.
6. Para testar a validação: chame a API com `start_date` depois de `end_date` — a API responde 422 com uma mensagem de erro.
