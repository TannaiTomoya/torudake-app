<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 非公開の写真を、Web とキューの共有グループが読める権限で保存する。
 */
class EvidenceFileStorage
{
    public function put(Company $company, UploadedFile $file, string $fileName): string
    {
        // mkdir は umask の影響を受ける。保存の間だけグループ権限を保ち、必ず元へ戻す。
        $previousMask = umask(0007);

        try {
            $path = Storage::disk((string) config('torudake.evidence.disk'))->putFileAs(
                "companies/{$company->id}",
                $file,
                $fileName,
                ['visibility' => 'private', 'directory_visibility' => 'private'],
            );

            if ($path === false) {
                throw new RuntimeException('写真を保存できませんでした。');
            }

            return $path;
        } finally {
            umask($previousMask);
        }
    }
}
