<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as FortifyTwoFactorLoginResponse;

class TwoFactorLoginResponse extends FortifyTwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $request->session()->forget('url.intended');

        return parent::toResponse($request);
    }
}
