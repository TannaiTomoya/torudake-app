<?php

namespace App\Services;

use App\Enums\AiStatus;
use App\Enums\SourceKind;
use App\Enums\SourceStatus;
use App\Jobs\ProcessEvidenceWithAi;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Evidence;
use App\Models\SourceRecord;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SubmitEvidence
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
    ];

    public function submit(Company $company, User $user, UploadedFile $file, ?string $note = null): SubmitResult
    {
        $path = $file->getRealPath();
        $sha256 = $path !== false ? hash_file('sha256', $path) : false;

        if ($sha256 === false) {
            throw new \RuntimeException('写真を読めませんでした。');
        }

        if ($company->evidences()->where('sha256', $sha256)->exists()) {
            return $this->duplicate($company, $sha256);
        }

        $mime = (string) $file->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? 'bin';
        $directory = "companies/{$company->id}";
        $fileName = Str::uuid7()->toString().'.'.$extension;
        $storageKey = "{$directory}/{$fileName}";
        $disk = Storage::disk((string) config('torudake.evidence.disk'));

        $disk->putFileAs($directory, $file, $fileName);

        try {
            $source = DB::transaction(function () use ($company, $user, $file, $note, $sha256, $mime, $storageKey): SourceRecord {
                $evidence = new Evidence;
                $evidence->forceFill([
                    'company_id' => $company->id,
                    'storage_key' => $storageKey,
                    'sha256' => $sha256,
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by' => $user->id,
                    'purge_after' => now()->addDays((int) config('torudake.evidence.retention_days')),
                ])->save();

                $source = new SourceRecord;
                $source->forceFill([
                    'company_id' => $company->id,
                    'kind' => SourceKind::Receipt,
                    'status' => SourceStatus::New,
                    'ai_status' => AiStatus::Waiting,
                    'ai_stage' => '受け取り',
                    'evidence_id' => $evidence->id,
                    'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                    'submitted_by' => $user->id,
                ])->save();

                AuditLog::record(
                    user: $user,
                    action: 'evidence.submit',
                    subject: $source,
                    after: ['evidence_id' => $evidence->id, 'mime' => $mime, 'size_bytes' => $evidence->size_bytes],
                    company: $company,
                );

                return $source;
            });
        } catch (UniqueConstraintViolationException) {
            $disk->delete($storageKey);

            return $this->duplicate($company, $sha256);
        } catch (Throwable $e) {
            $disk->delete($storageKey);

            throw $e;
        }

        ProcessEvidenceWithAi::dispatch($source->id);

        return new SubmitResult(duplicate: false, source: $source, monthCount: $this->monthCount($company));
    }

    private function duplicate(Company $company, string $sha256): SubmitResult
    {
        $existing = $company->sourceRecords()
            ->whereHas('evidence', fn ($query) => $query->where('sha256', $sha256))
            ->first();

        return new SubmitResult(duplicate: true, source: $existing, monthCount: $this->monthCount($company));
    }

    private function monthCount(Company $company): int
    {
        return $company->sourceRecords()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
