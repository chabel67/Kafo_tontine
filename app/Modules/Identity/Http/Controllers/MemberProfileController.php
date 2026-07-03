<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\PinService;
use App\Modules\Identity\Domain\Exceptions\PinInvalidException;
use App\Modules\Identity\Domain\Exceptions\PinLockedException;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class MemberProfileController extends Controller
{
    public function __construct(private readonly PinService $pinService) {}

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();

        $oldPath = $user->metadata['avatar_path'] ?? null;
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('photo')->store("avatars/{$user->id}", 'public');
        $url  = Storage::disk('public')->url($path);

        $metadata                 = $user->metadata ?? [];
        $metadata['avatar_url']   = $url;
        $metadata['avatar_path']  = $path;
        $user->update(['metadata' => $metadata]);

        return ApiResponse::success(new UserResource($user->fresh()));
    }

    public function changePin(Request $request): JsonResponse
    {
        $request->validate([
            'current_pin'          => ['required', 'string', 'size:6'],
            'new_pin'              => ['required', 'string', 'size:6'],
            'new_pin_confirmation' => ['required', 'string', 'same:new_pin'],
        ], [
            'new_pin_confirmation.same' => 'Les deux PIN ne correspondent pas.',
            'current_pin.size'          => 'Le PIN actuel doit contenir 6 chiffres.',
            'new_pin.size'              => 'Le nouveau PIN doit contenir 6 chiffres.',
        ]);

        $user = $request->user();

        try {
            $this->pinService->verify($user, $request->input('current_pin'));
        } catch (PinInvalidException) {
            return ApiResponse::error('pin_invalid', 'PIN actuel incorrect.', 422);
        } catch (PinLockedException $e) {
            return ApiResponse::error('pin_locked', "Compte temporairement bloqué. Réessayez dans {$e->lockSeconds} secondes.", 423);
        }

        try {
            $this->pinService->set($user, $request->input('new_pin'));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('pin_weak', $e->getMessage(), 422);
        }

        return ApiResponse::success(['message' => 'PIN modifié avec succès.']);
    }
}
