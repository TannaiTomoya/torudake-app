<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\LoginResponse as FortifyLoginResponse;

class LoginResponse extends FortifyLoginResponse
{
    public function toResponse($request)
    {
        // ログイン前のURLではなく、dashboard の役割別の振り分けを通す。
        $request->session()->forget('url.intended');

        return parent::toResponse($request);
    }
}
