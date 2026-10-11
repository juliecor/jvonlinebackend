<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Models\AccreditationDocument;
use App\Models\RealtyAccreditation;
use App\Support\AccreditationAgreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * An accredited realty's own accreditation, for its admins: how long it runs, the agreement to print
 * (with its dates), and the signed and notarized copy they upload for the developer to check.
 */
class MyAccreditationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $form = $this->mine($request);
        $copy = $form->documents()->where('kind', AccreditationDocument::SIGNED_AGREEMENT)->latest('id')->first();
        $snapshot = $form->agreement ?? AccreditationAgreement::snapshot($form->developer, $form);

        return response()->json([
            'developer' => $form->developer->name,
            'state' => $form->periodState(),
            'from' => $form->accredited_from?->toDateString(),
            'until' => $form->accredited_until?->toDateString(),
            'days_left' => $form->daysLeft(),
            'end_note' => $form->ended_at ? $form->end_note : null,
            'agreed' => $form->agreed_at ? ['name' => $form->agreed_name, 'at' => $form->agreed_at] : null,
            // Printed, signed on every page and notarized, then uploaded.
            'agreement' => AccreditationAgreement::ready($form->developer) || $form->agreement ? AccreditationAgreement::document($snapshot, $form->accredited_from?->toDateString(), $form->accredited_until?->toDateString()) : null,
            'signed_copy' => [
                'status' => $form->signed_copy_status,
                'due' => $form->signed_copy_due?->toDateString(),
                'note' => $form->signed_copy_note,
                'name' => $copy?->original_name,
                'uploaded_at' => $copy?->created_at,
            ],
        ]);
    }

    /** The signed and notarized agreement (a PDF or photos in one PDF); a new upload replaces the last one. */
    public function upload(Request $request): JsonResponse
    {
        $form = $this->mine($request);
        abort_if($form->signed_copy_status === 'verified', 409, 'Your signed agreement was already checked and accepted.');
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif']], [
            'file.mimes' => 'Upload the signed agreement as a PDF (or a photo).',
            'file.max' => 'The file can be up to 20 MB.',
        ]);
        $file = $request->file('file');
        $old = $form->documents()->where('kind', AccreditationDocument::SIGNED_AGREEMENT)->get();

        DB::transaction(function () use ($form, $file, $old) {
            $old->each->delete();
            $form->documents()->create([
                'kind' => AccreditationDocument::SIGNED_AGREEMENT,
                'path' => $file->store("accreditations/{$form->id}", AccreditationDocument::disk()),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ]);
            $form->update(['signed_copy_status' => 'submitted', 'signed_copy_note' => null]);
        });
        if ($old->isNotEmpty()) {
            Storage::disk(AccreditationDocument::disk())->delete($old->pluck('path')->all());
        }

        return $this->show($request);
    }

    /** This broker's accepted accreditation; a developer has none. */
    private function mine(Request $request): RealtyAccreditation
    {
        $realty = $request->user()->realty;
        abort_unless($realty->isBroker(), 404, 'Only an accredited realty has an accreditation.');

        return RealtyAccreditation::with('developer')->where('realty_id', $realty->id)->where('status', RealtyAccreditation::STATUS_APPROVED)->firstOrFail();
    }
}
