<?php

namespace App\Support;

use App\Models\Realty;
use App\Models\RealtyAccreditation;
use Illuminate\Support\Carbon;

/**
 * The Broker's Accreditation Agreement a realty agrees to on its accreditation
 * form, then prints, signs on every page and has notarized once the developer
 * accepts it. The terms follow the agreement Johndorf works from, with the
 * developer's own details (settings) and the realty's details filled in.
 * Changing the wording means a new VERSION; what a realty agreed to is kept
 * with its form (RealtyAccreditation::agreement).
 */
class AccreditationAgreement
{
    public const VERSION = '2026-1';

    /** How the developer sets the accreditation period: a year, to December 31, or a date it picks. */
    public const TERMS = ['1y', 'dec31', 'custom'];

    /**
     * The developer's details for the agreement, with what's not set yet as null.
     *
     * @return array{representative_name: ?string, representative_title: ?string, office_address: ?string, commission: array<int, array{classification: string, rate: string}>, signed_copy_days: int}
     */
    public static function settings(Realty $developer): array
    {
        $s = $developer->agreement_settings ?? [];

        return [
            'representative_name' => $s['representative_name'] ?? null,
            'representative_title' => $s['representative_title'] ?? null,
            'office_address' => $s['office_address'] ?? null,
            'commission' => array_values($s['commission'] ?? []),
            'signed_copy_days' => (int) ($s['signed_copy_days'] ?? 30),
        ];
    }

    /** Everything the agreement needs from the developer is filled in, so realties agree to it on their form. */
    public static function ready(Realty $developer): bool
    {
        $s = self::settings($developer);

        return filled($s['representative_name']) && filled($s['office_address']) && $s['commission'] !== [];
    }

    /**
     * What a realty agrees to: the parties and the commission scheme as they stood that day.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Realty $developer, RealtyAccreditation $form): array
    {
        $s = self::settings($developer);

        return [
            'version' => self::VERSION,
            'developer' => ['name' => $developer->name, 'address' => $s['office_address'], 'representative' => $s['representative_name'], 'title' => $s['representative_title']],
            'broker' => ['name' => $form->representative_name, 'firm' => $form->firm_name, 'address' => $form->residential_address, 'citizenship' => $form->citizenship],
            'commission' => $s['commission'],
        ];
    }

    /** The agreement from the developer's current settings, for the form (the realty's details are blanks it fills in) and the preview. */
    public static function draft(Realty $developer): array
    {
        $s = self::settings($developer);

        return self::document([
            'version' => self::VERSION,
            'developer' => ['name' => $developer->name, 'address' => $s['office_address'], 'representative' => $s['representative_name'], 'title' => $s['representative_title']],
            'broker' => ['name' => null, 'firm' => null, 'address' => null, 'citizenship' => null],
            'commission' => $s['commission'],
        ]);
    }

    /**
     * The whole agreement, ready to show or print: the opening with both parties, the numbered terms (8 holds the
     * commission table, 15 a list), the period (dates once accepted, else "from acceptance to the date set") and the
     * signature and notary blocks. Blanks are null: the page draws a line to fill in by hand.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function document(array $snapshot, ?string $from = null, ?string $until = null): array
    {
        $group = $snapshot['broker']['firm'] ?: null;
        $period = $from && $until ? 'from '.self::day($from).' to '.self::day($until) : 'from the date the FIRST PARTY accepts this accreditation until the end date the FIRST PARTY sets on accepting it';

        return [
            'version' => $snapshot['version'] ?? self::VERSION,
            'title' => "BROKER'S ACCREDITATION AGREEMENT",
            'developer' => $snapshot['developer'],
            'broker' => $snapshot['broker'],
            'commission' => $snapshot['commission'],
            'from' => $from,
            'until' => $until,
            'terms' => [
                ['text' => 'The FIRST PARTY appoints the SECOND PARTY as its selling unit to sell ALL PROJECTS owned, constructed, developed, managed or handled exclusively by the FIRST PARTY. The SECOND PARTY shall be under the group of '.($group ?? '__________').'.'],
                ['text' => 'The SECOND PARTY accepts the appointment and agrees to promote, market and sell the said PROJECTS at the prices, terms and conditions declared by the FIRST PARTY.'],
                ['text' => 'The SECOND PARTY agrees that the prices and terms of payment of any of the units of the PROJECTS may be changed from time to time by the FIRST PARTY. The FIRST PARTY may unilaterally remove or add certain PROJECTS from its list or change prices and payment terms based on a circulated pricelist.'],
                ['text' => 'The FIRST PARTY shall furnish the SECOND PARTY a complete set of all the necessary sales and marketing materials for the PROJECTS. However, it shall be the responsibility of the SECOND PARTY to make the necessary coordination with the FIRST PARTY from time to time to keep himself abreast on any and all developments about the PROJECTS.'],
                ['text' => 'The SECOND PARTY shall exert its best efforts to actively promote/sell all projects of the company in accordance with the trainings given by the FIRST PARTY through sales and marketing programs including personal servicing of clientele/prospective buyers, and provide prompt, efficient and reliable service.'],
                ['text' => 'The SECOND PARTY shall obtain, at its own expense, all necessary licenses and permits to enable him/her to conduct business as contemplated herein. The SECOND PARTY represents and warrants that it shall conduct business in strict conformity with appropriate laws, rules and regulations.'],
                ['text' => 'The SECOND PARTY shall provide the FIRST PARTY all the necessary details and information required from the clientele/prospective buyers/buyers in order that the FIRST PARTY may facilitate effectively and efficiently the drafting of the Contract to Sell/Deed of Sale in favor of the buyers. The SECOND PARTY agrees that the payment of its commission in accordance with Article 8 hereof shall only be made once all the necessary information and details required from the buyers are met.'],
                [
                    'text' => 'The FIRST PARTY shall pay to the SECOND PARTY a commission based on net selling price (net of EVAT and discount). Commission Scheme would be as follows:',
                    'commission' => true,
                    'after' => 'All taxes accruing on the receipt by the SECOND PARTY on his commission shall be the sole account of the SECOND PARTY. The SECOND PARTY authorizes the FIRST PARTY to withhold the corresponding creditable withholding tax due on the commission and to remit the same to the Bureau of Internal Revenue (BIR) or its successor agency.',
                ],
                ['text' => "This agreement shall be in effect {$period}. This agreement may be unilaterally terminated by the FIRST PARTY in the event the SECOND PARTY shall fail to perform any obligation imposed upon it by this agreement or the GUIDELINES set by the FIRST PARTY; or if the performance of the SECOND PARTY is below the declared selling standards; or if the SECOND PARTY commits such act or acts prejudicial to or detrimental to the interest, name and/or reputation of the FIRST PARTY, as may be determined by the FIRST PARTY such as but not limited to misrepresentation or deceitful acts committed against clients or prospective clients; or if the SECOND PARTY assigns any right/s under this agreement, without written application and without the FIRST PARTY'S prior written consent."],
                ['text' => 'The SECOND PARTY is an independent contractor and nothing contained in this Agreement shall be deemed or interpreted to constitute the SECOND PARTY as a partner or employee of the FIRST PARTY, nor shall either party have any authority to bind the other in any respect, it being understood and agreed that all applications submitted by the SECOND PARTY are subject to acceptance by the FIRST PARTY in its sole discretion.'],
                ['text' => 'The SECOND PARTY in the performance of his/her responsibilities under this Agreement shall keep the FIRST PARTY and its officers free from liability or loss that may arise from any act or omission or misrepresentation that the SECOND PARTY may commit or occasion.'],
                ['text' => 'It is also agreed and understood between the parties that there are no other agreement and understanding between them relating to the subject matter of this agreement. This Agreement supersedes all prior agreements, oral or written, between the parties and is intended as a complete and exclusive statement of the agreement between the parties. No change or modification of the agreement shall be valid unless the same be in writing and signed by the parties. However, the SECOND PARTY agrees that the Guidelines may be unilaterally amended from time to time by the FIRST PARTY, particularly with regard to business practice, to promote effectively and increase sales performance.'],
                ['text' => 'This agreement shall not be assigned nor transferred to another person or entity by the SECOND PARTY without prior written consent of the FIRST PARTY.'],
                ['text' => "The SECOND PARTY is strictly prohibited from advertising or publishing in magazines, radio, television, websites or purchasing domains, or any form of media on any information about the FIRST PARTY's PROJECTS without the permission from the FIRST PARTY. All expenses for the advertisement, when approved by the FIRST PARTY, shall be for the sole account of the SECOND PARTY. Should the SECOND PARTY not seek approval for the advertisement made by him through print, TV or any form of media, this shall be a ground for the automatic cancellation of the erring broker's accreditation including the forfeiture of commissions in favor of the FIRST PARTY. In addition, the SECOND PARTY shall be made to bear any/all legal consequences arising from such unauthorized advertisement or publication."],
                [
                    'text' => "Upon termination of this agreement, all rights and obligation of the parties under this agreement shall be extinguished except rights and obligations accruing prior to the effective date of termination. Each party shall make full settlement with the other whatever may be found due and owing. The commissions, if any, to be received by the SECOND PARTY for any uncompleted sales, i.e. sales that have not completed the required full down-payment and/or documents, and/or post-dated checks (PDC's), will become due and payable upon the completion of such. In the event the SECOND PARTY fails or ceases to serve any uncompleted sales, said commission for that particular transaction shall be forfeited in favor of the FIRST PARTY. Provided however, that all commissions of the SECOND PARTY shall be forfeited if the cause for termination shall be the commission of any act prejudicial and inimical to the interest of the FIRST PARTY such as but not limited to the following:",
                    'list' => [
                        "Misrepresentation of any kind whatsoever to the buyers and falsification of buyer's documents and other machinations, for the purpose of generating a sale/s;",
                        'Conniving with employee(s)/personnel of the FIRST PARTY to enable acceptance of buyers and/or documents in order to complete a sale;',
                        'Falsification of accountable documents such as Acknowledgment Receipt, Provisional Receipt, Reservation Documents and other machination tantamount to estafa or resulting in damage to the FIRST PARTY;',
                        'Pirating an account of other Sales People or Sales Team;',
                        'Disseminating exclusive and confidential information to competitor;',
                        'Non-remittance of any money or amount collected from prospective and/or existing buyers;',
                    ],
                ],
                ['text' => 'All notices required or permitted to be given hereunder shall be in writing and shall be sufficiently given when sent by registered mail to the address of each party as set forth in this agreement. The address of either party may be changed by giving written notice of such change to the other party. Notices may also be given by such other means as are acceptable by law.'],
                ['text' => 'This Accreditation Agreement is valid until '.($until ? self::day($until) : 'the end date the FIRST PARTY sets on accepting this accreditation').'.'],
            ],
        ];
    }

    /** "1y", "dec31" or "custom" (with a date) → the last day of the period, counted from $from. */
    public static function until(string $term, Carbon $from, ?string $custom = null): Carbon
    {
        return match ($term) {
            'dec31' => $from->copy()->endOfYear()->startOfDay(),
            'custom' => Carbon::parse($custom, 'Asia/Manila')->startOfDay(),
            default => $from->copy()->addYear()->subDay()->startOfDay(),
        };
    }

    private static function day(string $date): string
    {
        return Carbon::parse($date)->format('F j, Y');
    }
}
