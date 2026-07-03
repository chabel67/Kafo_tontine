<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\Enums\KycStatus;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class MemberKycController extends Controller
{
    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();

        if (in_array($user->kyc_status, [KycStatus::Pending, KycStatus::Verified], true)) {
            return ApiResponse::error(
                'kyc_not_submittable',
                $user->kyc_status === KycStatus::Pending
                    ? 'Votre dossier est déjà en cours d\'examen.'
                    : 'Votre identité est déjà vérifiée.',
                409,
            );
        }

        $level = (int) $request->input('level', 1);

        if ($level === 2 && $user->kyc_level < 1) {
            return ApiResponse::error('kyc_level_required', 'Le niveau 1 doit être vérifié avant de soumettre le niveau 2.', 422);
        }

        $rules = $level === 1
            ? [
                'level'     => ['required', 'integer', 'in:1,2'],
                'cni_front' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
                'cni_back'  => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
            ]
            : [
                'level'            => ['required', 'integer', 'in:1,2'],
                'selfie'           => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
                'proof_of_address' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:8192'],
            ];

        $request->validate($rules);

        $prefix   = "kyc/{$user->id}/level{$level}";
        $docs     = $user->kyc_documents ?? [];
        $levelKey = "level{$level}";

        // Delete previous documents for this level
        if (isset($docs[$levelKey])) {
            foreach (['cni_front_path', 'cni_back_path', 'selfie_path', 'proof_path'] as $key) {
                if (!empty($docs[$levelKey][$key])) {
                    Storage::disk('local')->delete($docs[$levelKey][$key]);
                }
            }
        }

        $entry = ['submitted_at' => now()->toIso8601String()];

        if ($level === 1) {
            $entry['cni_front_path'] = $request->file('cni_front')->store($prefix, 'local');
            if ($request->hasFile('cni_back')) {
                $entry['cni_back_path'] = $request->file('cni_back')->store($prefix, 'local');
            }
            $entry['has_cni_front'] = true;
            $entry['has_cni_back']  = isset($entry['cni_back_path']);
        } else {
            $entry['selfie_path'] = $request->file('selfie')->store($prefix, 'local');
            $entry['proof_path']  = $request->file('proof_of_address')->store($prefix, 'local');
            $entry['has_selfie']  = true;
            $entry['has_proof']   = true;
        }

        $docs[$levelKey] = $entry;

        $user->update([
            'kyc_documents' => $docs,
            'kyc_status'    => KycStatus::Pending,
        ]);

        return ApiResponse::success(new UserResource($user->fresh()));
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $docs = $user->kyc_documents ?? [];

        return ApiResponse::success([
            'kyc_level'  => $user->kyc_level,
            'kyc_status' => $user->kyc_status?->value,
            'level1'     => isset($docs['level1']) ? [
                'submitted_at'  => $docs['level1']['submitted_at'] ?? null,
                'has_cni_front' => $docs['level1']['has_cni_front'] ?? false,
                'has_cni_back'  => $docs['level1']['has_cni_back'] ?? false,
            ] : null,
            'level2'     => isset($docs['level2']) ? [
                'submitted_at' => $docs['level2']['submitted_at'] ?? null,
                'has_selfie'   => $docs['level2']['has_selfie'] ?? false,
                'has_proof'    => $docs['level2']['has_proof'] ?? false,
            ] : null,
        ]);
    }
}
