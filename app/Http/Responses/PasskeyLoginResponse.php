<?php

namespace App\Http\Responses;

use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse as BasePasskeyLoginResponse;

class PasskeyLoginResponse extends BasePasskeyLoginResponse
{
    public function toResponse($request)
    {
        // 顔でログインも、パスワードのときと同じく役割別の振り分けを通す。
        $request->session()->forget('url.intended');

        return parent::toResponse($request);
    }
}
