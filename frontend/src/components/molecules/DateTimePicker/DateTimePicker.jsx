import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CalendarIcon, ChevronLeftIcon, ChevronRightIcon, CloseIcon } from '../../atoms/Icon';
import styles from './DateTimePicker.module.css';

const WEEKDAYS = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
const MONTHS = [
  'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
  'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
];
/** Horários comuns para o fim da validade de uma galeria. */
const TIME_PRESETS = ['09:00', '12:00', '18:00', '23:59'];
const PANEL_WIDTH = 320;
const GAP = 6;

const pad = (value) => String(value).padStart(2, '0');
const toDateKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

/** "2026-09-25T18:00" → { dateKey: '2026-09-25', time: '18:00' } (e "2026-09-25" → sem hora) */
function splitValue(value) {
  const [dateKey = '', time = ''] = (value || '').split('T');

  return { dateKey, time: time.slice(0, 5) };
}

function formatDisplay(value) {
  const { dateKey, time } = splitValue(value);

  if (!dateKey) return '';

  const [year, month, day] = dateKey.split('-');

  return `${day}/${month}/${year}${time ? ` às ${time}` : ''}`;
}

/**
 * Seletor de data (e hora) do SnapManager: calendário e horário no mesmo
 * painel, com a identidade visual do sistema (temas claro/escuro, foco
 * visível, responsivo) no lugar do seletor padrão do navegador.
 *
 * Dois modos, e o valor entra e sai EXATAMENTE no formato do input nativo
 * que ele substitui — por isso nenhuma validação ou envio precisou mudar:
 *
 *   mode="datetime" (padrão) → "YYYY-MM-DDTHH:mm", como <input type="datetime-local">
 *   mode="date"              → "YYYY-MM-DD",       como <input type="date">
 *
 * O modo "date" é uma DATA CIVIL (prazo de entrega, prazo de assinatura):
 * não passa por conversão de fuso em lugar nenhum, então o dia escolhido é
 * o dia gravado. Quem precisa de instante absoluto (validade da galeria)
 * converte na hora de enviar, no próprio formulário.
 *
 * O painel é renderizado por portal no <body> com posição fixa: dentro de
 * um modal (que tem rolagem e transform próprios) ele seria cortado.
 */
export default function DateTimePicker({
  id,
  label,
  value,
  onChange,
  mode = 'datetime',
  min,
  max,
  error,
  hint,
  disabled,
  required = false,
  placeholder,
  quickActions = [],
}) {
  const isDateOnly = mode === 'date';
  const { dateKey: selectedDate, time: selectedTime } = splitValue(value);
  const minParts = splitValue(min);
  const maxParts = splitValue(max);

  const [isOpen, setIsOpen] = useState(false);
  const [position, setPosition] = useState(null);
  const [viewMonth, setViewMonth] = useState(() => {
    const base = selectedDate ? new Date(`${selectedDate}T12:00`) : new Date();

    return new Date(base.getFullYear(), base.getMonth(), 1);
  });

  const triggerRef = useRef(null);
  const panelRef = useRef(null);
  const gridRef = useRef(null);

  const todayKey = toDateKey(new Date());

  // Reabre sempre no mês do valor escolhido.
  useEffect(() => {
    if (!isOpen || !selectedDate) return;

    const base = new Date(`${selectedDate}T12:00`);
    setViewMonth(new Date(base.getFullYear(), base.getMonth(), 1));
  }, [isOpen, selectedDate]);

  useLayoutEffect(() => {
    if (!isOpen || !triggerRef.current) return;

    const rect = triggerRef.current.getBoundingClientRect();
    const height = panelRef.current?.offsetHeight ?? 0;
    const openUp = rect.bottom + GAP + height > window.innerHeight && rect.top - GAP - height > 0;

    setPosition({
      top: openUp ? Math.max(8, rect.top - GAP - height) : rect.bottom + GAP,
      left: Math.min(Math.max(8, rect.left), Math.max(8, window.innerWidth - PANEL_WIDTH - 8)),
    });
  }, [isOpen, viewMonth]);

  useEffect(() => {
    if (!isOpen) return undefined;

    function handlePointer(event) {
      if (!panelRef.current?.contains(event.target) && !triggerRef.current?.contains(event.target)) {
        setIsOpen(false);
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        // Não deixa o Esc fechar o modal por trás.
        event.stopPropagation();
        setIsOpen(false);
        triggerRef.current?.focus();
      }
    }

    document.addEventListener('mousedown', handlePointer);
    document.addEventListener('keydown', handleKeyDown, true);
    window.addEventListener('resize', () => setIsOpen(false), { once: true });

    return () => {
      document.removeEventListener('mousedown', handlePointer);
      document.removeEventListener('keydown', handleKeyDown, true);
    };
  }, [isOpen]);

  /** Primeiro horário permitido no dia (respeita o `min` quando é o mesmo dia). */
  function firstAllowedTime(dateKey) {
    return minParts.dateKey === dateKey ? minParts.time : '00:00';
  }

  function isDayDisabled(dateKey) {
    return (Boolean(minParts.dateKey) && dateKey < minParts.dateKey) || (Boolean(maxParts.dateKey) && dateKey > maxParts.dateKey);
  }

  function emit(dateKey, time) {
    if (!dateKey) {
      onChange('');

      return;
    }

    onChange(isDateOnly ? dateKey : `${dateKey}T${time}`);
  }

  function handleSelectDay(dateKey) {
    if (isDayDisabled(dateKey)) return;

    if (isDateOnly) {
      // Data civil: escolher o dia já é a resposta completa.
      emit(dateKey, '');
      setIsOpen(false);
      triggerRef.current?.focus();

      return;
    }

    // Sem horário ainda: 23:59 é o fim natural de uma validade — mas nunca
    // antes do mínimo permitido no dia.
    const candidate = selectedTime || '23:59';
    const floor = firstAllowedTime(dateKey);

    emit(dateKey, candidate < floor ? floor : candidate);
  }

  function handleSelectTime(time) {
    const dateKey = selectedDate || todayKey;
    const floor = firstAllowedTime(dateKey);

    emit(dateKey, time < floor ? floor : time);
  }

  /** Setas navegam entre os dias do mês em exibição. */
  function handleGridKeyDown(event) {
    const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    const step = steps[event.key];

    if (!step) return;

    event.preventDefault();
    const days = [...gridRef.current.querySelectorAll('button[data-day]:not(:disabled)')];
    const index = days.indexOf(document.activeElement);

    days[Math.min(days.length - 1, Math.max(0, index + step))]?.focus();
  }

  const firstWeekday = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1).getDay();
  const daysInMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 0).getDate();
  const cells = [...Array(firstWeekday).fill(null), ...Array.from({ length: daysInMonth }, (_, i) => i + 1)];

  return (
    <div className={styles.field}>
      {label && (
        <label className={styles.label} htmlFor={id}>
          {label}
        </label>
      )}

      <div className={styles.controls}>
        <button
          ref={triggerRef}
          id={id}
          type="button"
          className={`${styles.trigger} ${error ? styles.triggerInvalid : ''} ${value ? '' : styles.triggerEmpty}`}
          onClick={() => setIsOpen((open) => !open)}
          disabled={disabled}
          aria-haspopup="dialog"
          aria-expanded={isOpen}
          aria-required={required || undefined}
          aria-invalid={error ? true : undefined}
        >
          <CalendarIcon width={16} height={16} aria-hidden="true" />
          {formatDisplay(value) || placeholder || (isDateOnly ? 'Escolher data' : 'Escolher data e hora')}
        </button>

        {value && !disabled && !required && (
          <button type="button" className={styles.clear} onClick={() => onChange('')} aria-label={`Limpar ${label || 'data'}`} title="Limpar">
            <CloseIcon width={15} height={15} aria-hidden="true" />
          </button>
        )}
      </div>

      {quickActions.length > 0 && !disabled && (
        <div className={styles.quickActions}>
          {quickActions.map((action) => (
            <button key={action.label} type="button" className={styles.quickAction} onClick={action.onSelect} title={action.hint}>
              {action.label}
            </button>
          ))}
        </div>
      )}

      {hint && !error && <span className={styles.hint}>{hint}</span>}
      {error && <span className={styles.error}>{error}</span>}

      {isOpen &&
        createPortal(
          <div
            ref={panelRef}
            className={styles.panel}
            role="dialog"
            aria-label="Escolher data e hora"
            style={{ top: position?.top ?? -9999, left: position?.left ?? -9999, width: PANEL_WIDTH }}
          >
            <div className={styles.header}>
              <button
                type="button"
                className={styles.navButton}
                onClick={() => setViewMonth(new Date(viewMonth.getFullYear(), viewMonth.getMonth() - 1, 1))}
                aria-label="Mês anterior"
              >
                <ChevronLeftIcon width={16} height={16} aria-hidden="true" />
              </button>

              <span className={styles.monthLabel} aria-live="polite">
                {MONTHS[viewMonth.getMonth()]} de {viewMonth.getFullYear()}
              </span>

              <button
                type="button"
                className={styles.navButton}
                onClick={() => setViewMonth(new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 1))}
                aria-label="Próximo mês"
              >
                <ChevronRightIcon width={16} height={16} aria-hidden="true" />
              </button>
            </div>

            <div className={styles.weekdays} aria-hidden="true">
              {WEEKDAYS.map((weekday, index) => (
                <span key={`${weekday}-${index}`}>{weekday}</span>
              ))}
            </div>

            {/* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */}
            <div ref={gridRef} className={styles.grid} onKeyDown={handleGridKeyDown}>
              {cells.map((day, index) => {
                if (day === null) return <span key={`empty-${index}`} />;

                const dateKey = `${viewMonth.getFullYear()}-${pad(viewMonth.getMonth() + 1)}-${pad(day)}`;
                const isSelected = dateKey === selectedDate;
                const isToday = dateKey === todayKey;

                return (
                  <button
                    key={dateKey}
                    type="button"
                    data-day={dateKey}
                    className={[styles.day, isSelected ? styles.daySelected : '', isToday ? styles.dayToday : ''].filter(Boolean).join(' ')}
                    onClick={() => handleSelectDay(dateKey)}
                    disabled={isDayDisabled(dateKey)}
                    aria-pressed={isSelected}
                    aria-label={`${day} de ${MONTHS[viewMonth.getMonth()]} de ${viewMonth.getFullYear()}${isToday ? ' (hoje)' : ''}`}
                  >
                    {day}
                  </button>
                );
              })}
            </div>

            {!isDateOnly && (
            <div className={styles.timeSection}>
              <label className={styles.timeLabel} htmlFor={`${id}-time`}>
                Horário
              </label>

              <input
                id={`${id}-time`}
                type="time"
                className={styles.timeInput}
                value={selectedTime || ''}
                min={selectedDate === minParts.dateKey ? minParts.time : undefined}
                onChange={(event) => event.target.value && handleSelectTime(event.target.value)}
              />

              <div className={styles.presets}>
                {TIME_PRESETS.map((preset) => (
                  <button
                    key={preset}
                    type="button"
                    className={`${styles.preset} ${selectedTime === preset ? styles.presetActive : ''}`}
                    onClick={() => handleSelectTime(preset)}
                  >
                    {preset}
                  </button>
                ))}
              </div>
            </div>
            )}

            {!isDateOnly && (
              <div className={styles.footer}>
                <button type="button" className={styles.footerButton} onClick={() => setIsOpen(false)}>
                  Concluir
                </button>
              </div>
            )}
          </div>,
          document.body,
        )}
    </div>
  );
}
