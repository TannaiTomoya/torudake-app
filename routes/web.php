<?php

use App\Http\Controllers\EvidenceImageController;
use App\Http\Controllers\TrainingDataExportController;
use App\Http\Controllers\WorkbookDownloadController;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

$dispatchAuthenticatedUser = function (User $user): RedirectResponse|View {
    if ($user->homeMembership() === null) {
        return view('dashboard');
    }

    if ($user->two_factor_confirmed_at === null) {
        return redirect()->route('security.edit');
    }

    return redirect()->to($user->homeUrl());
};

Route::get('/', function (Request $request) use ($dispatchAuthenticatedUser): RedirectResponse|View {
    $user = $request->user();

    if ($user === null) {
        return redirect()->route('login');
    }

    return $dispatchAuthenticatedUser($user);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () use ($dispatchAuthenticatedUser): void {
    Route::get('dashboard', function (Request $request) use ($dispatchAuthenticatedUser): RedirectResponse|View {
        /** @var User $user */
        $user = $request->user();

        return $dispatchAuthenticatedUser($user);
    })->name('dashboard');
});

Route::middleware(['auth', 'verified', 'two-factor', 'company'])
    ->prefix('c/{company}')
    ->name('company.')
    ->scopeBindings()
    ->group(function (): void {
        Route::livewire('/', 'pages::company.home')->name('home');
        Route::livewire('submit', 'pages::company.submit')->name('submit');
        Route::livewire('sources', 'pages::company.sources.index')->name('sources.index');
        Route::livewire('sources/{source}', 'pages::company.sources.show')->name('sources.show');
        Route::livewire('entries', 'pages::company.entries.index')->name('entries.index');
        Route::livewire('entries/{entry}', 'pages::company.entries.show')->name('entries.show');
        Route::livewire('owner', 'pages::company.owner')->name('owner');
        Route::livewire('reports', 'pages::company.reports')->name('reports');
        Route::livewire('live', 'pages::company.live')->name('live');
        Route::livewire('ai', 'pages::company.ai')->name('ai');
        Route::livewire('settings', 'pages::company.settings')->name('settings');

        Route::get('evidences/{evidence}/image', EvidenceImageController::class)->name('evidences.image');
        Route::get('workbook.xlsx', WorkbookDownloadController::class)->name('workbook');

        Route::get('exports/training-data.zip', TrainingDataExportController::class)->name('exports.training');
    });

require __DIR__.'/settings.php';
