<?php

namespace App\Modules\Tontine\Jobs;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Application\RotationTrackingService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus as S;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Shared\BusinessTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lot 8 — rappels quotidiens des tontines rotatives (in-app, canal existant) :
 *  - membre : cotisation due demain ; tour qui approche (J-2) ;
 *  - personnel (diffusion) : tour du jour prêt à verser, ou bloqué avec le montant manquant.
 * Recalcule au passage le statut des tours. Idempotent à la journée (fenêtres de dates exactes).
 */
class RotationRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RotationTrackingService $tracking, NotificationService $notifications): void
    {
        $today    = BusinessTime::today();
        $tomorrow = $today->copy()->addDay()->toDateString();
        $inTwo    = $today->copy()->addDays(2)->toDateString();

        $campaigns = Campaign::where('campaign_type', 'rotating')->where('status', CampaignStatus::Active->value)->get();
        foreach ($campaigns as $campaign) {
            $tracking->refreshStatuses($campaign);

            Installment::with('membership:id,user_id')
                ->where('campaign_id', $campaign->id)
                ->where('status', InstallmentStatus::Pending->value)
                ->whereDate('due_date', $tomorrow)
                ->get()
                ->each(fn ($i) => $notifications->notify($i->membership?->user_id, 'rotation.contribution_reminder',
                    'Cotisation demain', "Tontine « {$campaign->name} » : cotisation de "
                    . number_format($i->amount_minor, 0, ',', ' ') . ' FCFA à régler demain.', '/echeancier'));

            foreach ($campaign->rounds()->with('beneficiary:id,user_id')->get() as $round) {
                $date = $round->scheduled_date->toDateString();
                if ($date === $inTwo && $round->beneficiary) {
                    $notifications->notify($round->beneficiary->user_id, 'rotation.round_upcoming', 'Votre tour approche',
                        "Tontine « {$campaign->name} » : votre cagnotte (tour {$round->round_number}) est prévue le "
                        . $round->scheduled_date->format('d/m/Y') . '.', '/campagnes');
                }
                if ($date === $today->toDateString() && in_array($round->status, [S::Ready, S::Blocked], true)) {
                    $missing = $tracking->coverage($round)['missing_minor'];
                    $notifications->broadcast(
                        $round->status === S::Ready ? 'rotation.round_ready' : 'rotation.round_blocked',
                        $round->status === S::Ready ? "Tour {$round->round_number} prêt à verser" : "Tour {$round->round_number} bloqué",
                        "Tontine « {$campaign->name} » : " . ($round->status === S::Ready
                            ? 'la cagnotte du jour peut être validée.'
                            : 'cotisations manquantes (' . number_format($missing, 0, ',', ' ') . ' FCFA) ou bénéficiaire en retard.'),
                        "/campaigns/{$campaign->id}",
                    );
                }
            }
        }
    }
}
