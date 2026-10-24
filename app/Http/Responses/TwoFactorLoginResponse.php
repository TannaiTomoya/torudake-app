<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\TrustedDevices;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as FortifyTwoFactorLoginResponse;

class TwoFactorLoginResponse extends FortifyTwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $request->session()->forget('url.intended');

        // 「この端末を覚える」にチェックがあれば、30日はこの端末でコードを聞かない
        $user = $request->user();
        if ($request->boolean('trust_device') && $user instanceof User) {
            app(TrustedDevices::class)->issue($request, $user);
        }

        return parent::toResponse($request);
    }
}
