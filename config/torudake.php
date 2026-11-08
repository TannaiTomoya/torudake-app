<?php

return [

    'evidence' => [
        'disk' => 'evidence',
        'retention_days' => (int) env('EVIDENCE_RETENTION_DAYS', 30),
    ],

    'ai' => [
        // claude（本物）／fake（開発・自動テスト）／replay（デモ用の会社で練習の記録を再生し、なければ本物）。未設定なら本物は呼ばない
        'driver' => env('AI_DRIVER', 'fake'),
        'daily_limit' => (int) env('AI_DAILY_LIMIT', 100),
        'timeout_seconds' => 30,
        'corrections_in_prompt' => 20,
    ],

    // 稼働確認（/health）。「生きている印」がこの秒数より古いと、200 ではなく 503 を返す（docs/runbook.md）
    'health' => [
        'scheduler_max_age_seconds' => 300,
        'queue_max_age_seconds' => 600,
    ],
];
