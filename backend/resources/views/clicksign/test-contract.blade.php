<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px 48px; }

    body {
        font-family: "Helvetica", "Arial", sans-serif;
        color: #1c1e26;
        font-size: 12px;
        line-height: 1.6;
    }

    .brand {
        font-size: 11px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #3757e0;
        font-weight: bold;
        margin: 0 0 2px;
    }

    h1 { font-size: 19px; margin: 0 0 6px; color: #12141c; }

    .stamp {
        border: 2px solid #c2410c;
        color: #c2410c;
        padding: 8px 12px;
        font-weight: bold;
        text-align: center;
        margin: 16px 0 20px;
    }

    h2 { font-size: 13px; margin: 18px 0 4px; }

    p { margin: 0 0 8px; text-align: justify; }

    .meta { color: #5b6070; font-size: 11px; }

    .signature { margin-top: 48px; }
    .signature .line { border-top: 1px solid #1c1e26; width: 60%; padding-top: 4px; }
</style>
</head>
<body>
    <p class="brand">SnapManager · Homologação</p>
    <h1>Contrato de Ensaio Fotográfico — Teste SnapManager</h1>
    <p class="meta">Referência: {{ $reference }} · Gerado em {{ $generatedAt }}</p>

    <div class="stamp">
        DOCUMENTO DE TESTE, SEM VALOR JURÍDICO — gerado apenas para homologar
        a integração com o ambiente Sandbox da Clicksign.
    </div>

    <h2>1. Partes</h2>
    <p>
        <strong>CONTRATADA:</strong> Estúdio Fictício SnapManager, empresa fictícia criada para testes.
        <strong>CONTRATANTE:</strong> Cliente de Teste, pessoa fictícia. Nenhum dado pessoal real
        consta deste documento.
    </p>

    <h2>2. Objeto</h2>
    <p>
        Realização de um ensaio fotográfico fictício de 2 (duas) horas, com entrega de 20 (vinte)
        fotografias tratadas em galeria online, conforme fluxo padrão do SnapManager.
    </p>

    <h2>3. Valor</h2>
    <p>R$ 0,00 (zero real). Valor simbólico — não há cobrança nem obrigação financeira.</p>

    <h2>4. Finalidade deste documento</h2>
    <p>
        Este arquivo existe somente para validar a criação de envelope, o envio de documento,
        o cadastro de signatário e a configuração de requisitos na API 3.0 da Clicksign
        (ambiente Sandbox). Assiná-lo não gera qualquer efeito jurídico.
    </p>

    <div class="signature">
        <div class="line">Assinatura eletrônica do signatário de teste</div>
    </div>
</body>
</html>
