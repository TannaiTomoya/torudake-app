<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Services\TrainingDataExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class TrainingDataExportController extends Controller
{
    public function __invoke(Request $request, Company $company, TrainingDataExporter $exporter): Response
    {
        Gate::authorize('exportTrainingData', $company);

        $monthParam = (string) $request->query('month', '');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $monthParam) ?: CarbonImmutable::now()->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $export = $exporter->export($company, $month);

        AuditLog::record($request->user(), 'training.export', $company, null, [
            'month' => $month->format('Y-m'),
            ...$export['counts'],
        ]);

        $filename = str_replace(['/', '\\'], '_', $company->name).'_学習データ_'.$month->format('Y-m').'.zip';

        return response($export['contents'], 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, 'torudake-training-'.$month->format('Y-m').'.zip'),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
