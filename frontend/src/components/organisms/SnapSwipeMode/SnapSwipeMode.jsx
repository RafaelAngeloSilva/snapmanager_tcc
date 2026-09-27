import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import EmptyState from '../../atoms/EmptyState';
import SwipeProgress from '../../molecules/SwipeProgress';
import SwipeActions from '../../molecules/SwipeActions';
import SwipePhotoCard from '../SwipePhotoCard';
import SwipeSummary from '../SwipeSummary';
import SwipeReview from '../SwipeReview';
import styles from './SnapSwipeMode.module.css';

/** Quantas fotos seguintes são pré-carregadas (miniaturas). */
const PRELOAD_AHEAD = 3;

/**
 * SnapSwipe: escolher foto a foto, deslizando.
 *
 * Fonte da verdade da SELEÇÃO é a mesma da galeria tradicional — o
 * `selectedIds` da página, gravado pelo mesmo endpoint de confirmação. Aqui
 * não existe banco de seleção paralelo.
 *
 * O que é só desta visão são as DECISÕES de navegação ("já avaliei esta
 * foto"): informação de sessão, que não altera nada no servidor e por isso
 * não precisou de tabela nem migration. Passar nunca apaga foto: significa
 * apenas "não selecionar agora", e a foto continua disponível na revisão.
 *
 * Desempenho: só dois cartões ficam montados (o atual e o próximo) e as
 * miniaturas seguintes são pré-carregadas em silêncio — uma galeria de
 * centenas de fotos não vira centenas de elementos na tela.
 */
export default function SnapSwipeMode({
  images,
  selectedIds,
  onToggle,
  onExpand,
  onConfirm,
  isConfirming,
  confirmError,
  confirmed,
  disabled,
  isLightboxOpen,
  selectionLimit = null,
}) {
  const [decisions, setDecisions] = useState({});
  const [cursor, setCursor] = useState(0);
  const [review, setReview] = useState(null); // null | 'selected' | 'all'
  const [limitWarning, setLimitWarning] = useState('');
  const undoStack = useRef([]);
  const [undoCount, setUndoCount] = useState(0);

  /** Uma foto está "avaliada" se foi decidida aqui ou já estava selecionada. */
  const decisionOf = useCallback(
    (image) => decisions[image.id] ?? (selectedIds.includes(image.id) ? 'selected' : null),
    [decisions, selectedIds],
  );

  const pendingIndexes = useMemo(
    () => images.map((image, index) => (decisionOf(image) ? null : index)).filter((index) => index !== null),
    [images, decisionOf],
  );

  const evaluated = images.length - pendingIndexes.length;
  const current = pendingIndexes.includes(cursor) ? cursor : (pendingIndexes[0] ?? null);
  const next = pendingIndexes.find((index) => index > current) ?? null;

  // Mantém o cursor em uma foto pendente (ex.: depois de desfazer ou voltar da revisão).
  useEffect(() => {
    if (current !== cursor) setCursor(current ?? 0);
  }, [current, cursor]);

  // Pré-carrega só as próximas miniaturas — nada de baixar a galeria toda.
  useEffect(() => {
    if (current === null) return;

    pendingIndexes
      .filter((index) => index > current)
      .slice(0, PRELOAD_AHEAD)
      .forEach((index) => {
        const preload = new Image();
        preload.src = images[index].thumbnail_url;
      });
  }, [current, pendingIndexes, images]);

  /**
   * Decide UMA foto, identificada pelo id.
   *
   * O id importa: o cartão avisa a decisão depois da animação de saída
   * (~180 ms) e, nesse meio-tempo, um clique ou uma seta poderia decidir a
   * foto seguinte sem a pessoa ter visto. Como a decisão é presa ao id e
   * fotos já avaliadas são ignoradas, cada gesto decide exatamente a sua
   * foto — e um clique repetido não avança duas.
   */
  const decide = useCallback(
    (direction, imageId = null) => {
      if (disabled) return;

      const index = imageId === null ? current : images.findIndex((image) => image.id === imageId);

      if (index === null || index < 0) return;

      const image = images[index];

      if (decisionOf(image)) return;

      const wasSelected = selectedIds.includes(image.id);

      // Limite de fotos (hoje a galeria não define nenhum; se passar a
      // definir, o aviso aparece e a seleção não avança).
      if (direction === 'select' && !wasSelected && selectionLimit && selectedIds.length >= selectionLimit) {
        setLimitWarning(`Você atingiu o limite de ${selectionLimit} fotos selecionadas. Use "Revisar selecionadas" para trocar alguma antes de escolher esta.`);

        return;
      }

      setLimitWarning('');

      // "Selecionar" garante que ela fique escolhida; "Passar" garante que
      // não fique — as duas mexem na MESMA seleção da galeria tradicional.
      if ((direction === 'select') !== wasSelected) {
        onToggle(image.id);
      }

      undoStack.current.push({ id: image.id, index, wasSelected, previousDecision: decisions[image.id] ?? null });
      setUndoCount(undoStack.current.length);

      setDecisions((prev) => ({ ...prev, [image.id]: direction === 'select' ? 'selected' : 'skipped' }));

      // Próxima pendente depois desta (a lista ainda não foi recalculada).
      setCursor(pendingIndexes.find((pending) => pending > index) ?? images.length);
    },
    [current, pendingIndexes, images, selectedIds, decisions, decisionOf, onToggle, disabled, selectionLimit],
  );

  const undo = useCallback(() => {
    const entry = undoStack.current.pop();
    setUndoCount(undoStack.current.length);

    if (!entry) return;

    setLimitWarning('');

    // Desfaz só a decisão daquela foto — nenhuma outra é tocada.
    if (selectedIds.includes(entry.id) !== entry.wasSelected) {
      onToggle(entry.id);
    }

    setDecisions((prev) => {
      const nextDecisions = { ...prev };

      if (entry.previousDecision) {
        nextDecisions[entry.id] = entry.previousDecision;
      } else {
        delete nextDecisions[entry.id];
      }

      return nextDecisions;
    });

    setCursor(entry.index);
  }, [selectedIds, onToggle]);

  /** Volta ao cartão de uma foto específica, sem recomeçar a galeria. */
  const jumpTo = useCallback((index) => {
    setDecisions((prev) => {
      const nextDecisions = { ...prev };
      delete nextDecisions[images[index].id];

      return nextDecisions;
    });
    setReview(null);
    setCursor(index);
  }, [images]);

  /** Rever todas: zera só as decisões de navegação — a seleção continua. */
  const restart = useCallback(() => {
    setDecisions({});
    undoStack.current = [];
    setUndoCount(0);
    setReview(null);
    setCursor(0);
  }, []);

  // Teclado (desktop). Desligado com a foto ampliada aberta: lá o lightbox
  // usa as próprias setas.
  useEffect(() => {
    if (disabled || isLightboxOpen || review !== null || current === null) return undefined;

    function handleKeyDown(event) {
      // Só ignora quando a pessoa está digitando; com o foco nos botões de
      // ação as setas continuam valendo.
      const target = event.target;
      const isTyping = target instanceof Element && (target.closest('input, textarea, select') || target.isContentEditable);

      if (isTyping) return;

      if (event.key === 'ArrowRight') {
        event.preventDefault();
        decide('select');
      } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        decide('skip');
      } else if (event.key === 'Backspace' || event.key.toLowerCase() === 'u') {
        event.preventDefault();
        undo();
      }
    }

    window.addEventListener('keydown', handleKeyDown);

    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [decide, undo, disabled, isLightboxOpen, review, current]);

  if (!images || images.length === 0) {
    return <EmptyState title="Nenhuma foto disponível" message="Ainda não há fotos nesta galeria." />;
  }

  if (review !== null) {
    return (
      <SwipeReview
        images={images}
        selectedIds={selectedIds}
        initialFilter={review}
        onToggle={onToggle}
        onJumpTo={jumpTo}
        onExpand={onExpand}
        onClose={() => setReview(null)}
        disabled={disabled}
      />
    );
  }

  return (
    <div className={styles.mode}>
      <SwipeProgress
        position={current === null ? null : current + 1}
        total={images.length}
        evaluated={evaluated}
        selected={selectedIds.length}
        limit={selectionLimit}
      />

      {current === null ? (
        <SwipeSummary
          total={images.length}
          selectedCount={selectedIds.length}
          onReviewSelected={() => setReview('selected')}
          // Passa o baralho de novo, desde a primeira foto. A SELEÇÃO
          // continua como está: só as decisões de navegação são zeradas.
          onReviewAll={restart}
          onFinish={onConfirm}
          isFinishing={isConfirming}
          error={confirmError}
          disabled={disabled}
          confirmed={confirmed}
        />
      ) : (
        <>
          <div className={styles.deck}>
            {next !== null && (
              <SwipePhotoCard
                key={images[next].id}
                image={images[next]}
                position={next + 1}
                total={images.length}
                isSelected={selectedIds.includes(images[next].id)}
                isTop={false}
                disabled
                onDecide={() => {}}
                onExpand={() => {}}
              />
            )}

            <SwipePhotoCard
              key={images[current].id}
              image={images[current]}
              position={current + 1}
              total={images.length}
              isSelected={selectedIds.includes(images[current].id)}
              isTop
              disabled={disabled}
              onDecide={(direction) => decide(direction, images[current].id)}
              onExpand={() => onExpand(current)}
            />
          </div>

          {limitWarning && (
            <p className={styles.limitWarning} role="alert">
              {limitWarning}
            </p>
          )}

          {disabled ? (
            <p className={styles.disabledNotice}>Esta galeria não está mais disponível para seleção — você pode apenas rever as fotos.</p>
          ) : (
            <>
              <SwipeActions
                onSkip={() => decide('skip')}
                onSelect={() => decide('select')}
                onUndo={undo}
                onExpand={() => onExpand(current)}
                canUndo={undoCount > 0}
                disabled={disabled}
              />

              <p className={styles.hint}>
                Deslize para a direita para selecionar e para a esquerda para passar. No computador, use as setas{' '}
                <kbd className={styles.kbd}>←</kbd> <kbd className={styles.kbd}>→</kbd> e <kbd className={styles.kbd}>U</kbd> para desfazer.
              </p>
            </>
          )}

          <button type="button" className={styles.reviewLink} onClick={() => setReview('selected')}>
            Revisar fotos selecionadas ({selectedIds.length})
          </button>
        </>
      )}
    </div>
  );
}
