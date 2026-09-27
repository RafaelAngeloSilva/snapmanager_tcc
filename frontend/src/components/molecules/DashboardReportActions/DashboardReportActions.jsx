import { useNavigate } from 'react-router-dom';
import Button from '../../atoms/Button';
import { ReportIcon } from '../../atoms/Icon';
import styles from './DashboardReportActions.module.css';

/**
 * Antes, este botão baixava direto o PDF (backend, DomPDF) do resumo do
 * Dashboard, sem gráficos — DomPDF não executa JS/React, então não dá pra
 * embutir os gráficos (Recharts) reais nele sem uma solução bem mais pesada
 * (renderizar imagem no servidor, etc.). A rota /dashboard/report resolve
 * isso: reaproveita os mesmos gráficos React já existentes, e o botão
 * "Imprimir / Salvar em PDF" de lá usa window.print() — o navegador gera um
 * PDF idêntico ao que se vê na tela, com os gráficos de verdade.
 *
 * O endpoint antigo (GET /api/dashboard/report, PDF) continua existindo no
 * back-end, só não é mais chamado por este botão.
 */
export default function DashboardReportActions() {
  const navigate = useNavigate();

  return (
    <div className={styles.wrapper}>
      <Button variant="secondary" onClick={() => navigate('/dashboard/report')}>
        <ReportIcon width={16} height={16} />
        Emitir relatório
      </Button>
    </div>
  );
}
