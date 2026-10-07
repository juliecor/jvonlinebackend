<?php

namespace App\Http\Controllers;

use App\Mail\OfferResponseMail;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\OfferResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** The buyer's page: one offer by its code (counts the view), and the buyer's answer to it. */
class PublicOfferController extends Controller
{
    public function show(Request $request, string $code): JsonResponse
    {
        $offer = $this->active($code);

        // The realty's own people (and admins) opening the link don't count as a buyer view.
        $viewer = auth('sanctum')->user();
        $insider = $viewer instanceof User && ($viewer->isAdmin() || $viewer->realty_id === $offer->realty_id);
        if (! $insider) {
            $offer->forceFill([
                'views' => $offer->views + 1,
                'first_viewed_at' => $offer->first_viewed_at ?? now(),
                'last_viewed_at' => now(),
            ])->save();
        }

        return response()->json($offer->publicArray());
    }

    public function respond(Request $request, string $code): JsonResponse
    {
        $offer = $this->active($code);
        $data = $request->validate([
            'kind' => ['required', Rule::in(OfferResponse::KINDS)],
            'name' => ['required', 'string', 'max:120'],
            'phone' => [Rule::requiredIf($request->input('kind') !== 'not_interested'), 'nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'contact_via' => ['nullable', Rule::in(['call', 'viber', 'whatsapp', 'sms', 'email'])],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'max:0'], // honeypot: people never see it, bots fill it
        ]);

        $response = OfferResponse::create([
            'offer_id' => $offer->id,
            'realty_id' => $offer->realty_id,
            'kind' => $data['kind'],
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'contact_via' => $data['contact_via'] ?? null,
            'message' => $data['message'] ?? null,
            'ip' => $request->ip(),
        ]);

        $to = $offer->agent?->email ?? $offer->realty->email;
        if ($to) {
            try {
                $url = rtrim(config('app.frontend_url'), '/')."/{$offer->realty->slug}/dashboard/offers/{$offer->id}";
                Mail::to($to)->send(new OfferResponseMail($offer->load(['unit', 'project']), $response, $url));
            } catch (\Throwable $e) {
                // The lead is saved either way; a mail hiccup shouldn't lose it or show the buyer an error.
                Log::warning('Offer response mail failed', ['offer' => $offer->code, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true, 'kind' => $response->kind], 201);
    }

    /**
     * The buyer information form (the fields of Johndorf's own reservation
     * form, plus work and income). "N/A" is fine for what doesn't apply.
     */
    public function details(Request $request, string $code): JsonResponse
    {
        $offer = $this->active($code);
        $text = fn (int $max = 150, bool $required = true) => [$required ? 'required' : 'nullable', 'string', "max:{$max}"];
        $data = $request->validate([
            'first_name' => $text(), 'middle_name' => $text(), 'last_name' => $text(), 'name_extension' => $text(20, false),
            'birth_date' => ['required', 'date', 'before:today'],
            'civil_status' => ['required', Rule::in(['Single', 'Married', 'Widowed', 'Legally separated/annulled'])],
            'gender' => ['required', Rule::in(['Male', 'Female'])],
            'citizenship' => $text(80), 'religion' => $text(80, false),
            'email' => ['required', 'email', 'max:190'], 'phone' => $text(40), 'messenger' => $text(120),
            'tin' => $text(40), 'sss' => $text(40), 'pagibig_mid' => $text(40, false),
            'zip' => $text(10), 'current_address' => $text(300), 'provincial_address' => $text(300),
            'home_ownership' => ['nullable', Rule::in(['Owned', 'Living with relatives or others', 'Renting'])],
            'rent_cost' => $text(40, false),
            'spouse_name' => $text(150, false),
            'income_source' => ['required', Rule::in(['employed', 'self_employed', 'ofw', 'online', 'commission', 'rental', 'transport', 'other'])],
            'employer' => $text(150, false), 'position' => $text(120, false), 'monthly_income' => $text(40, false),
            'co_borrower' => ['boolean'], 'co_borrower_name' => $text(150, false), 'co_borrower_relationship' => ['nullable', Rule::in(['Parent', 'Sibling', 'Child'])],
            'consent' => ['accepted'],
        ]);
        unset($data['consent']);
        $data['co_borrower'] = (bool) ($data['co_borrower'] ?? false);
        $data['consented_at'] = now()->toIso8601String();
        $data['ip'] = $request->ip();
        $offer->update(['buyer_details' => $data, 'details_submitted_at' => now()]);

        return response()->json(['requirements' => $offer->fresh()->requirementList(), 'details_submitted_at' => $offer->details_submitted_at]);
    }

    /** Files for one requirement: photos or PDFs, kept on the private documents disk. */
    public function upload(Request $request, string $code): JsonResponse
    {
        $offer = $this->active($code);
        $data = $request->validate([
            'requirement_type_id' => ['required', 'integer', Rule::exists('requirement_types', 'id')->where('realty_id', $offer->realty_id)->where('active', true)],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
        ], ['files.*.mimes' => 'Upload a photo (JPG, PNG, HEIC) or a PDF.', 'files.*.max' => 'Each file can be up to 10 MB.']);

        foreach ($request->file('files') as $file) {
            $path = $file->store("offers/{$offer->id}", OfferDocument::disk());
            abort_unless($path, 500, 'The file could not be saved. Please try again.');
            $offer->documents()->create([
                'realty_id' => $offer->realty_id,
                'requirement_type_id' => $data['requirement_type_id'],
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'ip' => $request->ip(),
            ]);
        }

        return response()->json(['requirements' => $offer->fresh()->requirementList()], 201);
    }

    /** The buyer takes back a file sent by mistake — only while nobody has reviewed it. */
    public function removeDocument(string $code, int $document): JsonResponse
    {
        $offer = $this->active($code);
        $doc = $offer->documents()->whereKey($document)->firstOrFail();
        abort_unless($doc->status === 'pending', 422, 'This file was already reviewed, so it can no longer be removed.');
        Storage::disk(OfferDocument::disk())->delete($doc->path);
        $doc->delete();

        return response()->json(['requirements' => $offer->fresh()->requirementList()]);
    }

    private function active(string $code): Offer
    {
        $offer = Offer::with(['realty', 'agent'])->where('code', strtoupper($code))->firstOrFail();
        if ($offer->status !== 'active') {
            abort(410, 'This offer is no longer available. Please ask your agent for a new one.');
        }

        return $offer;
    }
}
