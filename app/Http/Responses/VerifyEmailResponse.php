<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\VerifyEmailResponse as FortifyVerifyEmailResponse;

class VerifyEmailResponse extends FortifyVerifyEmailResponse
{
    public function toResponse($request)
    {
        $request->session()->forget('url.intended');

        return parent::toResponse($request);
    }
}
