const dateFormatter = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
const timeFormatter = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' });
const dateTimeFormatter = new Intl.DateTimeFormat('pt-BR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});
const longDateFormatter = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' });
const currencyFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

export function formatCurrency(value) {
  const number = Number(value);

  return currencyFormatter.format(Number.isFinite(number) ? number : 0);
}

export function formatDate(iso) {
  if (!iso) return '—';

  return dateFormatter.format(new Date(iso));
}

/**
 * Para campos "date" puros (ex.: due_date), o Laravel serializa sempre à
 * meia-noite UTC. Convertendo com `new Date()` e formatando no fuso local
 * (Brasil = UTC-3), a data pode "voltar" um dia. Por isso lemos o
 * ano/mês/dia direto da string, sem conversão de fuso horário.
 */
export function formatDueDate(iso) {
  if (!iso) return '—';

  const [year, month, day] = iso.slice(0, 10).split('-');

  return `${day}/${month}/${year}`;
}

/**
 * Mesma lógica de formatDueDate, mas retornando um objeto Date "neutro"
 * (meio-dia local) para comparações como "está atrasado?", evitando o
 * mesmo problema de fuso horário na hora de comparar com "hoje".
 */
export function parseDueDateAsLocalNoon(iso) {
  if (!iso) return null;

  const [year, month, day] = iso.slice(0, 10).split('-').map(Number);

  return new Date(year, month - 1, day, 12, 0, 0);
}

export function formatLongDate(iso) {
  if (!iso) return '—';

  return longDateFormatter.format(new Date(iso));
}

export function formatDateTime(iso) {
  if (!iso) return '—';

  return dateTimeFormatter.format(new Date(iso));
}

/**
 * Formata um intervalo "starts_at"–"ends_at" no padrão brasileiro,
 * mostrando a data uma única vez quando começa e termina no mesmo dia.
 */
export function formatDateTimeRange(startIso, endIso) {
  if (!startIso || !endIso) return '—';

  const start = new Date(startIso);
  const end = new Date(endIso);
  const sameDay = start.toDateString() === end.toDateString();

  if (sameDay) {
    return `${dateFormatter.format(start)} ${timeFormatter.format(start)}–${timeFormatter.format(end)}`;
  }

  return `${dateTimeFormatter.format(start)} – ${dateTimeFormatter.format(end)}`;
}

/**
 * Converte um ISO datetime (UTC, vindo da API) para o formato aceito por
 * <input type="datetime-local">, usando o horário local do navegador.
 */
export function toDatetimeLocalValue(iso) {
  if (!iso) return '';

  const date = new Date(iso);
  const pad = (value) => String(value).padStart(2, '0');

  const year = date.getFullYear();
  const month = pad(date.getMonth() + 1);
  const day = pad(date.getDate());
  const hours = pad(date.getHours());
  const minutes = pad(date.getMinutes());

  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

/**
 * Data/hora atual no formato aceito por <input type="datetime-local">
 * (horário local do navegador). Usado como "min" para bloquear datas
 * passadas em formulários de criação/edição.
 */
export function nowAsDatetimeLocalValue() {
  return toDatetimeLocalValue(new Date());
}

/**
 * "Agora + N horas" no formato local (YYYY-MM-DDTHH:mm). Usado pelo atalho
 * "Expirar em 48 horas": soma sobre o instante atual, então funciona mesmo
 * quando a soma cruza a virada do dia, do mês ou do horário de verão.
 */
export function datetimeLocalValueIn(hours) {
  const date = new Date();
  date.setTime(date.getTime() + hours * 60 * 60 * 1000);

  return toDatetimeLocalValue(date);
}

/**
 * Converte o valor local (YYYY-MM-DDTHH:mm, sem fuso) no INSTANTE em
 * ISO-8601 com offset ("2026-09-25T21:00:00.000Z").
 *
 * Sem isso, o back-end (que roda em UTC) leria "18:00" como 18:00 UTC — ou
 * seja, 15:00 em Brasília: a galeria expiraria três horas antes do que o
 * funcionário escolheu, e uma data válida poderia até ser recusada como
 * "no passado". Mandando o instante, o horário escolhido é exatamente o
 * horário gravado.
 */
export function datetimeLocalToIso(localValue) {
  if (!localValue) return null;

  const date = new Date(localValue);

  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * Data civil daqui a N dias (YYYY-MM-DD), montada a partir do calendário
 * LOCAL — nunca por toISOString(), que puxaria o dia para trás/frente
 * dependendo do fuso. Usada para limites de prazo (ex.: "no máximo 90
 * dias"), que são datas, não instantes.
 */
export function dateValueIn(days) {
  const date = new Date();
  date.setDate(date.getDate() + days);

  const pad = (value) => String(value).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Data atual no formato aceito por <input type="date"> (YYYY-MM-DD, horário
 * local do navegador — evita o mesmo problema de fuso horário do
 * formatDueDate/parseDueDateAsLocalNoon se usássemos toISOString()).
 */
export function todayAsDateValue() {
  const date = new Date();
  const pad = (value) => String(value).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
