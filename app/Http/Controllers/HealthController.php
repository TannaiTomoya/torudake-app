<?php

namespace App\Http\Controllers;

use App\Services\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 外から見る稼働確認。ログイン不要。応答は最小限（このアプリの印と ok だけ）で、構成・件数・会社の情報は出さない。
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $problems = $health->problems();

        if ($problems !== []) {
            Log::warning('稼働確認：問題あり', ['problems' => $problems]);
        }

        return response()->json(
            ['app' => 'torudake', 'ok' => $problems === []],
            $problems === [] ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
