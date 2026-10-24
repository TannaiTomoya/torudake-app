<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Evidence;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EvidenceImageController extends Controller
{
    public function __invoke(Company $company, string $evidence): Response
    {
        /** @var Evidence $record */
        $record = $company->evidences()->findOrFail($evidence);

        Gate::authorize('view', $record);

        if ($record->isPurged() || $record->storage_key === null) {
            abort(410, 'この写真は保存期間が過ぎたため削除されました。');
        }

        $disk = Storage::disk((string) config('torudake.evidence.disk'));

        if (! $disk->exists($record->storage_key)) {
            abort(410, 'この写真は削除されました。');
        }

        return $disk->response($record->storage_key, null, [
            'Content-Type' => $record->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
