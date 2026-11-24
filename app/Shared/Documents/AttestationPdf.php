<?php

namespace App\Shared\Documents;

use App\Shared\BusinessTime;
use App\Shared\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;

/**
 * Attestations PDF (R-DOC-01) : générées à la demande depuis les données en base,
 * jamais stockées. Réponse nominative : `Cache-Control: no-store` (R-AUTH-04).
 */
final class AttestationPdf
{
    /** HTML rendu (aussi utilisé par les tests pour vérifier le contenu). */
    public static function html(string $view, array $data): string
    {
        return view($view, self::viewData($data))->render();
    }

    public static function download(string $view, array $data, string $filename): Response
    {
        $pdf = Pdf::loadView($view, self::viewData($data))
            ->setPaper('a4')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);

        return new Response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, private',
        ]);
    }

    public static function money(?int $minor): string
    {
        return Money::format($minor);
    }

    /** Date (et heure) dans le fuseau métier : « 24/11/2026 à 16:52 ». */
    public static function date(?CarbonInterface $at, bool $withTime = true): string
    {
        if ($at === null) {
            return '—';
        }
        $local = $at->copy()->setTimezone(BusinessTime::timezone());

        return $withTime ? $local->format('d/m/Y \à H:i') : $local->format('d/m/Y');
    }

    /**
     * Numéro partiellement masqué sur un document qui circule : indicatif et 4 derniers
     * chiffres gardés (« +22901970000 28 » → « +229 ••••••• 0028 »).
     */
    public static function phone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '—';
        }
        $length = strlen($phone);
        if ($length <= 8) {
            return str_repeat('•', max(0, $length - 4)) . substr($phone, -4);
        }

        return substr($phone, 0, 4) . ' ' . str_repeat('•', $length - 8) . ' ' . substr($phone, -4);
    }

    private static function viewData(array $data): array
    {
        return $data + [
            'org'         => OrganizationProfile::current(),
            'generatedAt' => self::date(BusinessTime::now()),
        ];
    }
}
