@extends('attestations.layout')

@section('content')
    <p class="lead">
        {{ $org->name }} atteste avoir versé à {{ $beneficiary['name'] }} le retrait anticipé ci-dessous,
        prélevé sur son épargne de la campagne « {{ $campaignName }} ».
    </p>

    <div class="amount-box">
        <div class="label">Montant versé</div>
        <div class="value">{{ $amount }}</div>
    </div>

    <h2>Bénéficiaire</h2>
    <table class="kv">
        <tr><td class="k">Nom</td><td class="v">{{ $beneficiary['name'] }}</td></tr>
        <tr><td class="k">Téléphone</td><td class="v">{{ $beneficiary['phone'] }}</td></tr>
        <tr><td class="k">Campagne</td><td class="v">{{ $campaignName }}</td></tr>
    </table>

    <h2>Demande</h2>
    <table class="kv">
        <tr><td class="k">Demandé le</td><td class="v">{{ $requestedAt }}</td></tr>
        <tr><td class="k">Approuvé le</td><td class="v">{{ $decidedAt }}@if ($decidedBy) · par {{ $decidedBy }}@endif</td></tr>
    </table>

    <h2>Versement</h2>
    <table class="kv">
        <tr><td class="k">Date du versement</td><td class="v">{{ $disbursedAt }}</td></tr>
        <tr><td class="k">Moyen</td><td class="v">{{ $channel }}@if ($network) · {{ $network }}@endif</td></tr>
        @if ($payoutPhone)<tr><td class="k">Numéro crédité</td><td class="v">{{ $payoutPhone }}</td></tr>@endif
        @if ($externalRef)<tr><td class="k">Référence de l’opération</td><td class="v">{{ $externalRef }}</td></tr>@endif
        @if ($agent)<tr><td class="k">Versé par</td><td class="v">{{ $agent }}</td></tr>@endif
        @if ($ledgerRef)<tr><td class="k">Transaction comptable</td><td class="v">{{ $ledgerRef }}</td></tr>@endif
    </table>

    <div class="note">
        Ce montant est prélevé sur l’épargne du bénéficiaire. Son adhésion à la campagne se poursuit et le retrait
        n’a entraîné aucun frais.
    </div>

    <p class="statement">
        Le bénéficiaire reconnaît avoir reçu la somme de {{ $amount }}.
    </p>
@endsection
