import { useEffect, useRef, useState } from 'react';
import Input from '../../atoms/Input';
import Spinner from '../../atoms/Spinner';
import { fetchClients } from '../../../api/clients';
import styles from './ClientPicker.module.css';
import { FIELD_LIMITS } from '../../../utils/fieldLimits';

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Busca + seleção de cliente ativo (usa GET /api/clients?search=...).
 * Reaproveitado pelo formulário de agendamento, pelo de pedidos e, em modo
 * `compact`, pelo filtro por cliente na listagem de pedidos.
 */
export default function ClientPicker({ value, onChange, error, label = 'Cliente *', compact = false }) {
  const [term, setTerm] = useState('');
  const [results, setResults] = useState([]);
  const [isSearching, setIsSearching] = useState(false);
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef(null);

  useEffect(() => {
    function handleClickOutside(event) {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    }

    document.addEventListener('mousedown', handleClickOutside);

    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    if (!isOpen) return undefined;

    setIsSearching(true);

    const controller = new AbortController();

    const handle = setTimeout(() => {
      fetchClients({ page: 1, search: term, signal: controller.signal })
        .then((response) => setResults(response.data))
        .catch((err) => {
          if (err.code === 'ERR_CANCELED') return;
          setResults([]);
        })
        .finally(() => setIsSearching(false));
    }, SEARCH_DEBOUNCE_MS);

    // Cancela tanto o timer do debounce quanto uma requisição já em voo —
    // cobre o caso de duas teclas digitadas com mais de 300ms entre si,
    // onde as duas chegam a disparar fetch e a mais lenta poderia
    // sobrescrever a mais recente ao responder depois.
    return () => {
      clearTimeout(handle);
      controller.abort();
    };
  }, [term, isOpen]);

  function handleSelect(client) {
    onChange(client);
    setIsOpen(false);
    setTerm('');
  }

  function handleChangeClick() {
    onChange(null);
    setTerm('');
    setIsOpen(true);
  }

  const classes = [styles.wrapper, compact ? styles.compact : ''].filter(Boolean).join(' ');
  const placeholder = compact ? 'Buscar cliente...' : 'Buscar cliente por nome, e-mail, telefone ou documento...';

  return (
    <div className={classes} ref={containerRef}>
      {label && <span className={styles.label}>{label}</span>}

      {value && !isOpen ? (
        <div className={styles.selectedChip}>
          <span className={styles.selectedChipName}>{value.name}</span>
          <button type="button" className={styles.changeButton} onClick={handleChangeClick}>
            Trocar
          </button>
        </div>
      ) : (
        <div className={styles.searchBox}>
          <Input
            value={term}
            onChange={(event) => setTerm(event.target.value)}
            maxLength={FIELD_LIMITS.SEARCH}
            onFocus={() => setIsOpen(true)}
            placeholder={placeholder}
            invalid={Boolean(error)}
            autoComplete="off"
          />

          {isOpen && (
            <div className={styles.dropdown}>
              {isSearching && (
                <div className={styles.dropdownState}>
                  <Spinner size="sm" /> Buscando...
                </div>
              )}

              {!isSearching && results.length === 0 && (
                <div className={styles.dropdownState}>Nenhum cliente encontrado.</div>
              )}

              {!isSearching &&
                results.map((client) => (
                  <button key={client.id} type="button" className={styles.option} onClick={() => handleSelect(client)}>
                    <span className={styles.optionName}>{client.name}</span>
                    {(client.email || client.phone) && (
                      <span className={styles.optionMeta}>{client.email || client.phone}</span>
                    )}
                  </button>
                ))}
            </div>
          )}
        </div>
      )}

      {error && <span className={styles.errorText}>{error}</span>}
    </div>
  );
}
