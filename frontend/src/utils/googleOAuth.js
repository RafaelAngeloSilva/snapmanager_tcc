import { API_ROOT_URL } from '../api/client';

/**
 * Login com Google — parte do navegador.
 *
 * Antes de ir para o Google, a aba gera um NONCE aleatório e guarda em
 * sessionStorage (só desta aba, apagado ao fechar). Na volta, o código de
 * uso único só é trocado por um token junto com esse nonce: um link com o
 * código de OUTRA pessoa não faz login nesta aba (proteção contra login
 * CSRF). O nonce não é segredo de longo prazo nem credencial — nenhuma
 * senha ou token fica guardado no navegador por este fluxo.
 */
const NONCE_KEY = 'snapmanager:google-oauth-nonce';

function base64Url(bytes) {
  return btoa(String.fromCharCode(...bytes))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

export function startGoogleLogin() {
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);
  const nonce = base64Url(bytes);

  sessionStorage.setItem(NONCE_KEY, nonce);
  window.location.assign(`${API_ROOT_URL}/auth/google/redirect?nonce=${encodeURIComponent(nonce)}`);
}

/** Lê e APAGA o nonce (uso único). */
export function consumeGoogleNonce() {
  const nonce = sessionStorage.getItem(NONCE_KEY);
  sessionStorage.removeItem(NONCE_KEY);

  return nonce;
}

/** Mensagens para /login?google_error=<motivo> (motivos definidos no back-end). */
export const GOOGLE_ERROR_MESSAGES = {
  cancelled: 'Login com Google cancelado.',
  expired: 'O login com Google expirou. Tente novamente.',
  invalid_state: 'Não foi possível confirmar o login com Google. Tente novamente.',
  invalid_request: 'Não foi possível iniciar o login com Google. Tente novamente.',
  invalid_profile: 'O Google não retornou os dados necessários da sua conta.',
  unverified_email: 'O e-mail da sua conta Google ainda não foi verificado pelo Google.',
  account_conflict: 'Este e-mail já está vinculado a outra conta Google. Entre com e-mail e senha.',
  no_account: 'Não há conta no SnapManager para este e-mail. Peça a um administrador para criar o seu acesso.',
  exchange_failed: 'O login com Google expirou ou é inválido. Tente novamente.',
  failed: 'Não foi possível entrar com o Google. Tente novamente.',
};

export function googleErrorMessage(reason) {
  return GOOGLE_ERROR_MESSAGES[reason] || GOOGLE_ERROR_MESSAGES.failed;
}
