# Autenticação: Google, recuperação de senha e e-mail

Complementa o [deploy-railway.md](deploy-railway.md). Valores reais (Client
Secret, chave da Brevo) ficam **só** nas variáveis da Railway — nunca no Git,
no `.env.example` ou no React.

URLs de produção usadas abaixo:
- back-end: `https://snapmanagertcc-production.up.railway.app`
- front-end: `https://snapmanager.vercel.app`

---

## 1. Como funciona

### Login tradicional
`POST /api/login` (e-mail + senha) → token Sanctum (Bearer, expira em 7 dias).
Senha errada e e-mail inexistente dão a **mesma** resposta. Limite: 5/min por
e-mail+IP, 20/min por IP.

### Login com Google (Laravel Socialite)

```
React                               Laravel                                   Google
gera nonce (sessionStorage) ──► GET /auth/google/redirect?nonce=…   ──►  escolhe a conta
                                guarda hash do nonce na sessão + state
                                GET /auth/google/callback?code&state ◄──  autoriza / cancela
                                confere state, acha/vincula/cria usuário
/login/google/callback#code ◄── código de uso único (60 s)
POST /api/auth/google/exchange {code, nonce} ──► token Sanctum
```

- **O token nunca aparece numa URL.** O callback devolve um *código* de uso
  único, válido por 60 s, no fragmento (`#`), que o navegador não envia para
  servidores nem logs. O React troca esse código pelo token.
- **Proteção contra login CSRF:** o código só vale junto com o nonce criado
  pela **mesma aba** que clicou em "Continuar com Google". Um link com o
  código de outra pessoa não loga a vítima na conta do atacante.
- **`state` do OAuth:** guardado na sessão pelo Socialite e conferido na volta.
- **Redirects fixos:** sempre `FRONTEND_URL` + caminho fixo. Nenhum parâmetro
  da URL escolhe o destino.
- **Nenhum token do Google é guardado.** O sistema só guarda o id ("sub"), o
  nome e o e-mail. Para conta nova, guarda também uma cópia da foto (baixada só
  de `*.googleusercontent.com`).

**Qual conta é usada:**

| Situação | Resultado |
|---|---|
| `google_id` já vinculado | Entra nessa conta (mesmo se o e-mail no Google mudou) |
| E-mail igual a uma conta existente **e** e-mail verificado pelo Google | Vincula o Google à conta existente (sem duplicar) — ver abaixo o que acontece com a senha |
| E-mail não verificado pelo Google | Recusado (`unverified_email`) |
| E-mail já vinculado a **outra** conta Google | Recusado (`account_conflict`) |
| Conta não existe e `REGISTRATION_ENABLED=true` | Cria conta de **atendente** sem senha local |
| Conta não existe e cadastro fechado (produção) | Recusado (`no_account`): "peça a um administrador" |
| Usuário cancela no Google | Volta ao login com "Login com Google cancelado." |

**A senha antiga continua valendo?** Só se o e-mail daquela conta já tinha
sido comprovado (`email_verified_at` preenchido, o que acontece ao entrar com o
Google ou ao usar um link de "Esqueci minha senha"). Se nunca foi comprovado,
quem criou a conta pode não ser o dono do e-mail: alguém poderia cadastrar o
e-mail da vítima com uma senha própria e esperar ela entrar com o Google
("pré-sequestro de conta"). Por isso, nesse caso, o vínculo **remove a senha
antiga e encerra todas as sessões** da conta. O dono continua entrando com o
Google e, se quiser senha de novo, usa "Esqueci minha senha".

Banco: `users.google_id` (**UNIQUE**: uma conta Google ↔ um usuário),
`users.google_linked_at` e `users.password` agora **pode ser nulo**
(migration `2026_10_12_100000_add_google_auth_to_users_table`). Usuários
antigos não mudam.

### Esqueci minha senha
1. `POST /api/forgot-password` sempre responde: *"Se existir uma conta
   associada a este e-mail, enviaremos as instruções para redefinição da
   senha."* O e-mail é enviado **depois** da resposta, então o tempo de
   resposta não revela se a conta existe.
2. O broker do Laravel gera o token, guarda só o **hash** dele, define validade
   de **60 min** e limita a 1 e-mail por minuto por conta. A rota tem ainda o
   limite de 5 pedidos/min por IP.
3. O e-mail (em português) leva ao front-end:
   `FRONTEND_URL/reset-password?token=…&email=…`. Ao abrir, a página tira o
   token da barra de endereços.
4. `POST /api/reset-password` valida o token (uso único, expira) e as regras de
   senha (8–72, confirmação igual, **diferente da atual**). Marca o e-mail como
   comprovado, encerra todas as sessões e responde "Senha redefinida com
   sucesso.".

Para link inválido, expirado, já usado ou e-mail inexistente, a mensagem é a
mesma: *"Este link de redefinição é inválido ou expirou."*

### Conta criada só pelo Google
Não tem senha local, e login por senha sempre falha para ela. Para criar uma
senha, a pessoa usa **"Esqueci minha senha"**: o link enviado para o e-mail
prova que ela tem acesso a ele, e a conta passa a entrar das duas formas. No
perfil, logada, ela **não** consegue criar a primeira senha sem esse link, para
que uma sessão roubada não possa criar uma senha permanente.

---

## 2. Google Cloud (uma vez)

1. Acesse [console.cloud.google.com](https://console.cloud.google.com) →
   crie/selecione um projeto (ex.: "SnapManager").
2. **Google Auth Platform → Branding:** nome do app "SnapManager", e-mail de
   suporte. Em **Authorized domains**, adicione `vercel.app` e `railway.app`
   (ou seus domínios próprios).
3. **Audience:** tipo **External**. Enquanto o app estiver em "Testing", só os
   e-mails cadastrados em **Test users** conseguem entrar. Para liberar a
   qualquer conta Google, clique em **Publish app**. Com os escopos básicos
   (`openid`, `email`, `profile`) não é preciso passar pela verificação do
   Google.
4. **Clients → Create client → Web application:**
   - **Authorized redirect URIs:**
     - `https://snapmanagertcc-production.up.railway.app/auth/google/callback`
     - (desenvolvimento) `http://localhost:8000/auth/google/callback`
   - **Authorized JavaScript origins:** não são necessárias (o fluxo é feito
     pelo servidor).
5. Copie o **Client ID** e o **Client Secret** para as variáveis da Railway.
   O Google mostra o Client Secret **uma única vez**: se perder, gere outro em
   *Rotate secret*.

Erro `redirect_uri_mismatch` = a URI cadastrada é diferente de
`GOOGLE_REDIRECT_URI`, até numa barra no final. Mudanças no console podem
levar alguns minutos para valer.

---

## 3. Brevo (envio de e-mail)

A Railway bloqueia SMTP de saída nos planos Trial e Hobby. Só APIs HTTPS
funcionam. Por isso o envio usa a API da Brevo
(`App\Mail\Transport\BrevoTransport`, sem pacote extra).

1. Crie a conta em [brevo.com](https://www.brevo.com) (plano gratuito: 300
   e-mails/dia).
2. **Senders, Domains & Dedicated IPs → Senders → Add a sender:** o e-mail que
   vai aparecer como remetente. A Brevo manda um código para confirmar.
   - Melhor: um domínio próprio autenticado (SPF/DKIM) em **Domains**.
   - Remetente `@gmail.com` funciona para testes, mas tende a cair no spam.
3. **SMTP & API → API Keys → Generate a new API key** → copie para
   `BREVO_API_KEY` na Railway.
4. Teste depois do deploy: peça "Esqueci minha senha" com um e-mail cadastrado
   e confira a caixa de entrada (e o spam). Falhas aparecem nos logs como
   `Brevo: envio recusado (HTTP 401, unauthorized)`, sem a chave.

Para usar SMTP (plano Pro da Railway ou outro host), basta trocar
`MAIL_MAILER=smtp` e preencher `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD` e `MAIL_SCHEME`. O código não muda.

---

## 4. Variáveis de ambiente

### Produção (Railway, serviço `snapmanager_tcc`)

| Variável | Valor | Observação |
|---|---|---|
| `APP_URL` | `https://snapmanagertcc-production.up.railway.app` | Já existe |
| `FRONTEND_URL` | `https://snapmanager.vercel.app` | Já existe: links do e-mail e destino do Google |
| `GOOGLE_CLIENT_ID` | do Google Cloud | |
| `GOOGLE_CLIENT_SECRET` | do Google Cloud | **secreto** |
| `GOOGLE_REDIRECT_URI` | `https://snapmanagertcc-production.up.railway.app/auth/google/callback` | Sem `/api` |
| `MAIL_MAILER` | `brevo` | |
| `BREVO_API_KEY` | da Brevo | **secreto** |
| `MAIL_FROM_ADDRESS` | remetente verificado na Brevo | |
| `MAIL_FROM_NAME` | `SnapManager` | |
| `SESSION_DRIVER` | `database` | Já documentado; o fluxo do Google usa sessão |
| `SESSION_SECURE_COOKIE` | `true` | Opcional: já é o padrão quando `APP_URL` é https |
| `REGISTRATION_ENABLED` | `false` | Contas Google novas também respeitam isso |

Nada muda na Vercel: o React não recebe Client ID nem Secret, só chama o
back-end.

### Desenvolvimento (`backend/.env`)
- `MAIL_MAILER=log`: o e-mail vai para `storage/logs/laravel.log`.
- Google: crie um client com a redirect URI
  `http://localhost:8000/auth/google/callback`, ou deixe as variáveis vazias
  para esconder o botão.

---

## 5. Depois do deploy

A migration roda sozinha no Pre-Deploy Command. Depois:

1. Abra `https://snapmanager.vercel.app/login`: o botão "Continuar com Google"
   aparece quando as 3 variáveis `GOOGLE_*` estão preenchidas.
2. "Esqueci minha senha" → o e-mail chega → redefinir → entrar com a nova
   senha. Isso também comprova o e-mail da conta.
3. Entre com uma conta Google cujo e-mail já existe no SnapManager: ela é
   vinculada à conta existente, sem criar outra.

**Atenção às contas que já existem:** nenhuma conta criada antes desta
versão tem o e-mail comprovado. Quem entrar com o Google **antes** de usar
o "Esqueci minha senha" perde a senha antiga (continua entrando pelo Google).
Para manter as duas formas de entrar, faça a etapa 2 antes da 3.
