<?php

namespace App\Console\Commands;

use App\Mail\AccreditationPeriodMail;
use App\Models\RealtyAccreditation;
use App\Models\User;
use App\Support\QuietMail;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Daily: an accreditation ending within 30 days, and one that has just ended, is emailed once each
 * to the accredited realty's admins and to the developer's staff who handle realties.
 */
class RemindAccreditations extends Command
{
    protected $signature = 'accreditations:remind';

    protected $description = 'Email accredited realties and their developer when an accreditation is about to end, and when it has ended';

    public function handle(): int
    {
        $forms = RealtyAccreditation::with(['developer', 'realty'])->where('status', RealtyAccreditation::STATUS_APPROVED)
            ->whereNull('ended_at')->whereNotNull('accredited_until')->whereNotNull('realty_id')->get();
        $sent = 0;

        foreach ($forms as $form) {
            $left = $form->daysLeft();
            $state = match (true) {
                $left < 0 && $form->ended_notified_at === null => 'ended',
                $left >= 0 && $left <= RealtyAccreditation::ENDING_SOON_DAYS && $form->expiry_reminded_at === null => 'ending',
                default => null,
            };
            if ($state === null || ! $form->realty) {
                continue;
            }

            $frontend = rtrim(config('app.frontend_url'), '/');
            foreach ($this->admins($form->realty_id, null) as $to) {
                $sent += (int) QuietMail::send($to->email, new AccreditationPeriodMail($form, $state, false, "{$frontend}/{$form->realty->slug}/dashboard/accreditation"), "accreditation {$state} {$form->id}");
            }
            foreach ($this->admins($form->developer_id, 'realties') as $to) {
                $sent += (int) QuietMail::send($to->email, new AccreditationPeriodMail($form, $state, true, "{$frontend}/{$form->developer->slug}/dashboard/realties/{$form->id}"), "accreditation {$state} {$form->id}");
            }
            $form->update([$state === 'ended' ? 'ended_notified_at' : 'expiry_reminded_at' => now()]);
        }

        $this->info("Sent {$sent} accreditation email(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, User> A realty's active staff with the section, or its full admins when none is named. */
    private function admins(int $realtyId, ?string $section)
    {
        return User::where('realty_id', $realtyId)->where('role', User::ROLE_REALTY)->where('status', User::STATUS_ACTIVE)->get()
            ->filter(fn (User $u) => $section === null ? $u->isFullAdmin() : $u->canUse($section))->values();
    }
}
