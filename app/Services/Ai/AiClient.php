<?php

namespace App\Services\Ai;

interface AiClient
{
    /**
     * @throws AiException 通信の失敗・時間切れ・API のエラー
     */
    public function complete(AiRequest $request): AiResult;

    public function driverName(): string;

    public function modelName(): string;
}
