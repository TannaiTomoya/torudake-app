<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\WorkbookService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class WorkbookDownloadController extends Controller
{
    public function __invoke(Company $company, WorkbookService $workbooks): Response
    {
        Gate::authorize('exportExcel', $company);

        $filename = str_replace(['/', '\\'], '_', $company->name).'_帳簿.xlsx';

        return response($workbooks->contents($company), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, 'torudake.xlsx'),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
