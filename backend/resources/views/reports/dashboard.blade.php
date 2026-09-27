<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 34px; }

    body {
        font-family: "Helvetica", "Arial", sans-serif;
        color: #1c1e26;
        font-size: 12px;
        line-height: 1.5;
    }

    .brand {
        font-size: 11px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #3757e0;
        font-weight: bold;
        margin: 0 0 2px;
    }

    h1 {
        font-size: 20px;
        margin: 0 0 12px;
        color: #12141c;
    }

    .meta {
        margin-bottom: 18px;
        color: #52555e;
        font-size: 11px;
    }

    .meta span {
        display: inline-block;
        margin-right: 22px;
    }

    h2 {
        font-size: 13px;
        margin: 0 0 8px;
        padding-bottom: 5px;
        border-bottom: 1.5px solid #e7e4df;
        color: #12141c;
    }

    .section {
        margin-bottom: 18px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    .cards td {
        width: 25%;
        padding: 10px 12px;
        border: 1px solid #e7e4df;
        vertical-align: top;
    }

    .card-label {
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #94969e;
        margin: 0 0 4px;
    }

    .card-value {
        font-size: 17px;
        font-weight: bold;
        color: #12141c;
        margin: 0;
    }

    .data-table th,
    .data-table td {
        text-align: left;
        padding: 6px 10px;
        border: 1px solid #e7e4df;
        font-size: 11px;
    }

    .data-table th {
        background: #faf9f7;
        color: #52555e;
        font-weight: bold;
    }

    .align-right {
        text-align: right;
    }

    .badge {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 8px;
        font-size: 9.5px;
        font-weight: bold;
    }

    .badge-high { background: #fef3f2; color: #d92d20; }
    .badge-medium { background: #fffaeb; color: #b54708; }
    .badge-info { background: #eff8ff; color: #175cd3; }

    .empty-note {
        color: #94969e;
        font-style: italic;
        font-size: 11px;
    }

    .footer-note {
        margin-top: 24px;
        font-size: 9.5px;
        color: #94969e;
        border-top: 1px solid #e7e4df;
        padding-top: 8px;
    }
</style>
</head>
<body>
    <p class="brand">SnapManager</p>
    <h1>Relatório Operacional</h1>

    <div class="meta">
        <span><strong>Período analisado:</strong>
            @if ($start_date || $end_date)
                {{ $start_date ?? 'início' }} até {{ $end_date ?? 'hoje' }}
            @else
                Geral (dados atuais do sistema)
            @endif
        </span>
        <span><strong>Emitido em:</strong> {{ $issued_at->format('d/m/Y H:i') }}</span>
        @if ($issued_by)
            <span><strong>Emitido por:</strong> {{ $issued_by }}</span>
        @endif
    </div>

    <div class="section">
        <h2>Resumo geral</h2>
        <table class="cards">
            <tr>
                <td>
                    <p class="card-label">Clientes cadastrados</p>
                    <p class="card-value">{{ $clients['total'] }}</p>
                </td>
                <td>
                    <p class="card-label">Agendamentos hoje</p>
                    <p class="card-value">{{ $appointments['today'] }}</p>
                </td>
                <td>
                    <p class="card-label">Agendamentos futuros</p>
                    <p class="card-value">{{ $appointments['upcoming'] }}</p>
                </td>
                <td>
                    <p class="card-label">Agendamentos no período</p>
                    <p class="card-value">{{ $appointments['in_period'] }}</p>
                </td>
            </tr>
            <tr>
                <td>
                    <p class="card-label">Pedidos atrasados</p>
                    <p class="card-value">{{ $orders['overdue'] }}</p>
                </td>
                <td>
                    <p class="card-label">Prazo próximo (7 dias)</p>
                    <p class="card-value">{{ $orders['due_soon'] }}</p>
                </td>
                <td>
                    <p class="card-label">Prontos para entrega</p>
                    <p class="card-value">{{ $orders['ready_for_delivery'] }}</p>
                </td>
                <td>
                    <p class="card-label">Pedidos no período</p>
                    <p class="card-value">{{ $orders['total_in_period'] }}</p>
                </td>
            </tr>
            <tr>
                <td>
                    <p class="card-label">Receita entregue</p>
                    <p class="card-value">R$ {{ number_format($financial['revenue_delivered'], 2, ',', '.') }}</p>
                </td>
                <td>
                    <p class="card-label">Receita pendente</p>
                    <p class="card-value">R$ {{ number_format($financial['revenue_pending'], 2, ',', '.') }}</p>
                </td>
                <td>
                    <p class="card-label">Galerias aguardando escolha</p>
                    <p class="card-value">{{ $galleries['awaiting_selection_galleries'] }}</p>
                </td>
                <td>
                    <p class="card-label">Galerias com escolha concluída</p>
                    <p class="card-value">{{ $galleries['completed_selection_galleries'] }}</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Pedidos por status @if ($start_date || $end_date) (criados no período) @endif</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th class="align-right">Quantidade</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders['by_status'] as $status => $count)
                    <tr>
                        <td>{{ \App\Enums\OrderStatus::from($status)->label() }}</td>
                        <td class="align-right">{{ $count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Resumo de galerias</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Indicador</th>
                    <th class="align-right">Quantidade</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Total de galerias</td><td class="align-right">{{ $galleries['total_galleries'] }}</td></tr>
                <tr><td>Ativas</td><td class="align-right">{{ $galleries['active_galleries'] }}</td></tr>
                <tr><td>Expiradas</td><td class="align-right">{{ $galleries['expired_galleries'] }}</td></tr>
                <tr><td>Encerradas</td><td class="align-right">{{ $galleries['closed_galleries'] }}</td></tr>
                <tr><td>Aguardando escolha do cliente</td><td class="align-right">{{ $galleries['awaiting_selection_galleries'] }}</td></tr>
                <tr><td>Escolha concluída</td><td class="align-right">{{ $galleries['completed_selection_galleries'] }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Principais alertas operacionais</h2>

        @if (count($alerts) === 0)
            <p class="empty-note">Nenhum alerta ativo no momento.</p>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Severidade</th>
                        <th>Alerta</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($alerts as $alert)
                        <tr>
                            <td><span class="badge badge-{{ $alert['severity'] }}">{{ ucfirst($alert['severity']) }}</span></td>
                            <td>{{ $alert['message'] }}</td>
                            <td>{{ $alert['date'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="footer-note">
        Relatório gerado automaticamente pelo SnapManager em {{ $issued_at->format('d/m/Y \à\s H:i') }}.
        Indicadores de estado atual (atrasados, prontos para entrega, status de galeria) refletem a operação no momento da emissão;
        agendamentos e pedidos "no período" consideram apenas o intervalo de datas informado, quando aplicável.
    </p>
</body>
</html>
