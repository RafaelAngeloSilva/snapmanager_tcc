import { useMemo, useState } from 'react';
import Button from '../../atoms/Button';
import Select from '../../atoms/Select';
import {
  CalendarIcon,
  CameraIcon,
  ChatIcon,
  CheckCircleIcon,
  CheckIcon,
  ContractIcon,
  EditIcon,
  ImageIcon,
  NoteIcon,
  PackageIcon,
  PlusIcon,
  QuoteIcon,
  SendIcon,
  StarIcon,
  TrashIcon,
  UserIcon,
  UsersIcon,
  WalletIcon,
  XCircleIcon,
} from '../../atoms/Icon';
import styles from './ClientTimeline.module.css';

const FILTERS = [
  { value: 'all', label: 'Todos' },
  { value: 'shoots', label: 'Ensaios' },
  { value: 'financial', label: 'Financeiro' },
  { value: 'service', label: 'Atendimento' },
  { value: 'post_sale', label: 'Pós-Venda' },
  { value: 'contracts', label: 'Contratos' },
];

const AUTO_ICONS = {
  client_registered: UserIcon,
  shoot_scheduled: CalendarIcon,
  shoot_completed: CameraIcon,
  shoot_cancelled: XCircleIcon,
  order_created: WalletIcon,
  order_delivered: PackageIcon,
  contract_created: ContractIcon,
  contract_sent: SendIcon,
  contract_signed: CheckCircleIcon,
  gallery_created: ImageIcon,
  gallery_selection_completed: CheckIcon,
};

const MANUAL_ICONS = {
  first_contact: ChatIcon,
  briefing: UsersIcon,
  shoot_day: CameraIcon,
  post_sale: StarIcon,
  general: NoteIcon,
};

const dayFormatter = new Intl.DateTimeFormat('pt-BR', { day: '2-digit' });
const monthFormatter = new Intl.DateTimeFormat('pt-BR', { month: 'short' });
const yearFormatter = new Intl.DateTimeFormat('pt-BR', { year: 'numeric' });
const timeFormatter = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' });
const shortDate = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });

/** Anotação manual tem só DIA: lê da string (sem fuso). Automático: data/hora local. */
function eventDate(event) {
  if (event.source === 'manual' && event.entry?.event_date) {
    const [year, month, day] = event.entry.event_date.split('-').map(Number);
    return new Date(year, month - 1, day, 12);
  }

  return new Date(event.date);
}

function dateParts(event) {
  const date = eventDate(event);

  return {
    day: dayFormatter.format(date),
    month: monthFormatter.format(date).replace('.', '').toUpperCase(),
    year: yearFormatter.format(date),
    time: event.source === 'manual' ? null : timeFormatter.format(date),
  };
}

function describe(event) {
  const scheduledFor = event.meta?.scheduled_for;

  if (scheduledFor && event.description) {
    const when = new Date(scheduledFor);
    const base = event.description.replace(/\.$/, '');
    const verb = event.type === 'shoot_cancelled' ? ' (estava marcado para' : ' para';
    const end = event.type === 'shoot_cancelled' ? ').' : '.';

    return `${base}${verb} ${shortDate.format(when)} às ${timeFormatter.format(when)}${end}`;
  }

  return event.description;
}

export default function ClientTimeline({ events, onAdd, onEdit, onDelete, canAdd }) {
  const [filter, setFilter] = useState('all');
  const [order, setOrder] = useState('desc');

  const visible = useMemo(() => {
    const filtered = filter === 'all' ? events : events.filter((event) => event.group === filter);

    return order === 'desc' ? filtered : [...filtered].reverse();
  }, [events, filter, order]);

  // "Histórico relevante" = qualquer coisa além do próprio cadastro.
  const hasHistory = events.some((event) => event.type !== 'client_registered');

  return (
    <section className={styles.wrapper} aria-labelledby="timeline-title">
      <header className={styles.header}>
        <h2 id="timeline-title" className={styles.title}>
          Linha do tempo
        </h2>
        {canAdd && (
          <Button onClick={onAdd}>
            <PlusIcon />
            Adicionar anotação
          </Button>
        )}
      </header>

      {!hasHistory ? (
        <div className={styles.empty}>
          <NoteIcon width={28} height={28} aria-hidden="true" className={styles.emptyIcon} />
          <h3>A história com este cliente está começando.</h3>
          <p>Registre preferências e informações importantes para oferecer um atendimento cada vez mais personalizado.</p>
          {canAdd && (
            <Button onClick={onAdd}>
              <PlusIcon />
              Adicionar primeira anotação
            </Button>
          )}
        </div>
      ) : (
        <>
          <div className={styles.toolbar}>
            <div className={styles.filters} role="group" aria-label="Filtrar eventos">
              {FILTERS.map((item) => (
                <button
                  key={item.value}
                  type="button"
                  className={`${styles.filter} ${filter === item.value ? styles.filterActive : ''}`}
                  aria-pressed={filter === item.value}
                  onClick={() => setFilter(item.value)}
                >
                  {item.label}
                </button>
              ))}
            </div>
            <Select
              aria-label="Ordenar eventos"
              value={order}
              onChange={(event) => setOrder(event.target.value)}
              className={styles.order}
            >
              <option value="desc">Mais recentes</option>
              <option value="asc">Mais antigos</option>
            </Select>
          </div>

          {visible.length === 0 ? (
            <p className={styles.noResults}>Nenhum evento nesta categoria ainda.</p>
          ) : (
            <ol className={styles.list}>
              {visible.map((event) => {
                const isManual = event.source === 'manual';
                const Icon = isManual ? MANUAL_ICONS[event.entry?.category] || NoteIcon : AUTO_ICONS[event.type] || CheckCircleIcon;
                const parts = dateParts(event);
                const description = describe(event);

                return (
                  <li key={event.key} className={`${styles.item} ${styles[`group_${event.group}`] || ''}`}>
                    <span className={`${styles.marker} ${isManual ? styles.markerManual : ''}`} aria-hidden="true">
                      <Icon width={15} height={15} />
                    </span>

                    <div className={styles.body}>
                      <time className={styles.when} dateTime={isManual ? event.entry.event_date : event.date}>
                        {parts.day} {parts.month} {parts.year}
                        {parts.time ? ` · ${parts.time}` : ''}
                      </time>

                      <div className={styles.titleRow}>
                        <h3 className={styles.eventTitle}>{event.title}</h3>
                        {isManual && <span className={styles.category}>{event.entry.category_label}</span>}
                        {isManual && event.entry.is_testimonial_candidate && (
                          <span className={styles.testimonial} title="Potencial depoimento">
                            <QuoteIcon width={12} height={12} aria-hidden="true" />
                            Depoimento
                          </span>
                        )}
                      </div>

                      {description && <p className={styles.description}>{description}</p>}

                      {(event.appointment || (isManual && event.entry.author)) && (
                        <p className={styles.context}>
                          {event.appointment && <span>{event.appointment.title}</span>}
                          {isManual && event.entry.author && <span>Anotado por {event.entry.author}</span>}
                        </p>
                      )}
                    </div>

                    {isManual && event.entry.can_edit && (
                      <div className={styles.actions}>
                        <Button variant="ghost" size="icon" onClick={() => onEdit(event)} aria-label={`Editar anotação ${event.title}`} title="Editar">
                          <EditIcon />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => onDelete(event)} aria-label={`Excluir anotação ${event.title}`} title="Excluir">
                          <TrashIcon />
                        </Button>
                      </div>
                    )}
                  </li>
                );
              })}
            </ol>
          )}
        </>
      )}
    </section>
  );
}
