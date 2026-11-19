<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\TrialBalanceReport;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class TrialBalanceDownloadController extends Controller
{
    public function __invoke(Company $company, string $month, TrialBalanceReport $report): Response
    {
        Gate::authorize('viewTrialBalance', $company);

        $filename = str_replace(['/', '\\'], '_', $company->name).'_試算表_'.$month.'.xlsx';

        return response($report->spreadsheet($company, $month), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, 'trial-balance-'.$month.'.xlsx'),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
