<?php

namespace App\Services\Ai;

use RuntimeException;

class AiException extends RuntimeException
{
    public static function invalidOutput(string $detail): self
    {
        return new self("AIの返事の形が違います：{$detail}");
    }

    public static function dailyLimit(): self
    {
        return new self('今日のAIの上限に達しました。手で入力してください。');
    }
}
