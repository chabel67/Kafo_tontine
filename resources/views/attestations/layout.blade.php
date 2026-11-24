<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $title }} — {{ $reference }}</title>
<style>
    /* dompdf : CSS 2.1, mise en page par tableaux. Charte Kafo (CLAUDE.md §5). */
    @page { margin: 22mm 18mm 26mm 18mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #1A1A1A; font-size: 10.5px; line-height: 1.45; margin: 0; }
    .header { width: 100%; border-bottom: 2px solid #1B5E3F; padding-bottom: 10px; }
    .header td { vertical-align: top; }
    .brand { font-size: 17px; font-weight: bold; color: #1B5E3F; }
    .contact { color: #555555; font-size: 9.5px; margin-top: 2px; }
    .doc-ref { text-align: right; color: #555555; font-size: 9.5px; }
    .doc-ref strong { color: #1A1A1A; font-size: 11px; }
    h1 { font-size: 16px; margin: 20px 0 4px; color: #1A1A1A; }
    .lead { color: #555555; margin: 0 0 14px; }
    .amount-box { background: #D9EBE2; border-radius: 6px; padding: 10px 14px; margin: 6px 0 16px; }
    .amount-box .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #1B5E3F; font-weight: bold; }
    .amount-box .value { font-size: 20px; font-weight: bold; color: #1A1A1A; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: #1B5E3F; margin: 16px 0 6px; }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 4px 0; border-bottom: 1px solid #E6E6E6; vertical-align: top; }
    table.kv td.k { width: 38%; color: #6B6B6B; }
    table.kv td.v { font-weight: bold; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.grid th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6B6B6B; border-bottom: 1px solid #1B5E3F; padding: 4px 6px; }
    table.grid td { padding: 4px 6px; border-bottom: 1px solid #E6E6E6; }
    table.grid .num { text-align: right; white-space: nowrap; }
    .note { background: #F6EBD7; border-left: 3px solid #C8852A; padding: 8px 10px; margin-top: 12px; color: #1A1A1A; }
    .statement { margin-top: 16px; }
    table.signatures { width: 100%; margin-top: 26px; border-collapse: collapse; page-break-inside: avoid; }
    table.signatures td { width: 50%; vertical-align: top; padding-right: 18px; }
    .sig-title { font-weight: bold; }
    .sig-sub { color: #6B6B6B; font-size: 9.5px; }
    .sig-line { border-bottom: 1px solid #999999; height: 52px; margin-top: 6px; }
    .footer { position: fixed; bottom: -16mm; left: 0; right: 0; border-top: 1px solid #E6E6E6; padding-top: 5px; color: #6B6B6B; font-size: 8.5px; }
</style>
</head>
<body>
    <div class="footer">
        @if ($org->footer){{ $org->footer }} · @endif
        Document généré le {{ $generatedAt }} à partir des écritures enregistrées. Référence {{ $reference }}.
    </div>

    <table class="header">
        <tr>
            <td>
                <div class="brand">{{ $org->name }}</div>
                @if ($org->contactLine())<div class="contact">{{ $org->contactLine() }}</div>@endif
            </td>
            <td class="doc-ref">
                Référence<br><strong>{{ $reference }}</strong><br>
                Édité le {{ $generatedAt }}
            </td>
        </tr>
    </table>

    <h1>{{ $title }}</h1>

    @yield('content')

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-title">Le bénéficiaire</div>
                <div class="sig-sub">{{ $beneficiary['name'] }} — « Lu et approuvé », date et signature</div>
                <div class="sig-line"></div>
            </td>
            <td>
                <div class="sig-title">Pour {{ $org->name }}</div>
                <div class="sig-sub">{{ $agent ?? 'Nom de l’agent' }} — signature et cachet</div>
                <div class="sig-line"></div>
            </td>
        </tr>
    </table>
</body>
</html>
