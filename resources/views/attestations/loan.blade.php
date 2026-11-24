@extends('attestations.layout')

@section('content')
    <p class="lead">
        {{ $org->name }} atteste avoir accordé et versé {{ $isAdvance ? 'l’avance' : 'le prêt' }} ci-dessous
        à {{ $beneficiary['name'] }}.
    </p>

    <div class="amount-box">
        <div class="label">Montant versé</div>
        <div class="value">{{ $principal }}</div>
    </div>

    <h2>Bénéficiaire</h2>
    <table class="kv">
        <tr><td class="k">Nom</td><td class="v">{{ $beneficiary['name'] }}</td></tr>
        <tr><td class="k">Téléphone</td><td class="v">{{ $beneficiary['phone'] }}</td></tr>
    </table>

    <h2>Prêt</h2>
    <table class="kv">
        <tr><td class="k">Type</td><td class="v">{{ $productLabel }}</td></tr>
        @if ($campaignName)<tr><td class="k">Campagne</td><td class="v">{{ $campaignName }}</td></tr>@endif
        <tr><td class="k">Montant accordé</td><td class="v">{{ $principal }}</td></tr>
        @if ($interestRate)<tr><td class="k">Taux d’intérêt</td><td class="v">{{ $interestRate }}</td></tr>@endif
        @if ($interestTotal)<tr><td class="k">Intérêts</td><td class="v">{{ $interestTotal }}</td></tr>@endif
        <tr><td class="k">Total à rembourser</td><td class="v">{{ $totalToRepay }}</td></tr>
        @if (! $isAdvance && $installments)
            <tr><td class="k">Remboursement</td><td class="v">{{ $installments }} échéance{{ $installments > 1 ? 's' : '' }}@if ($periodicity) · {{ mb_strtolower($periodicity) }}@endif</td></tr>
        @endif
    </table>

    <h2>Versement</h2>
    <table class="kv">
        <tr><td class="k">Date du versement</td><td class="v">{{ $disbursedAt }}</td></tr>
        <tr><td class="k">Moyen</td><td class="v">{{ $channel }}@if ($network) · {{ $network }}@endif</td></tr>
        @if ($payoutPhone)<tr><td class="k">Numéro crédité</td><td class="v">{{ $payoutPhone }}</td></tr>@endif
        @if ($agent)<tr><td class="k">Versé par</td><td class="v">{{ $agent }}</td></tr>@endif
        @if ($ledgerRef)<tr><td class="k">Transaction comptable</td><td class="v">{{ $ledgerRef }}</td></tr>@endif
    </table>

    @if ($isAdvance)
        <div class="note">
            Cette avance est adossée à l’épargne du bénéficiaire. Elle n’a pas d’échéancier : le montant restant dû
            est déduit de son épargne lors du règlement de clôture de la campagne.
        </div>
    @elseif (count($schedule) > 0)
        <h2>Échéancier de remboursement</h2>
        <table class="grid">
            <thead>
                <tr><th>N°</th><th>Échéance</th><th class="num">Capital</th><th class="num">Intérêts</th><th class="num">Total</th></tr>
            </thead>
            <tbody>
                @foreach ($schedule as $row)
                    <tr>
                        <td>{{ $row['n'] }}</td>
                        <td>{{ $row['date'] }}</td>
                        <td class="num">{{ $row['principal'] }}</td>
                        <td class="num">{{ $row['interest'] }}</td>
                        <td class="num">{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="statement">
        Le bénéficiaire reconnaît avoir reçu la somme de {{ $principal }} et s’engage à la rembourser
        selon les conditions ci-dessus.
    </p>
@endsection
