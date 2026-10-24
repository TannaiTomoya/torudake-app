<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

$dispatchAuthenticatedUser = function (User $user): RedirectResponse|View {
    $membership = $user->primaryActiveMembership();

    if ($membership !== null && $membership->company !== null) {
        if ($user->two_factor_confirmed_at === null) {
            return redirect()->route('security.edit');
        }

        if ($membership->submit_only) {
            return redirect()->route('company.submit', $membership->company);
        }

        return redirect()->route('company.home', $membership->company);
    }

    return view('dashboard');
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
        Route::livewire('close/{month}', 'pages::company.close')
            ->where('month', '[0-9]{4}-(0[1-9]|1[0-2])')
            ->name('close');
        Route::livewire('live', 'pages::company.live')->name('live');
        Route::livewire('ai', 'pages::company.ai')->name('ai');
        Route::livewire('settings', 'pages::company.settings')->name('settings');

        Route::get('evidences/{evidence}/image', function (Company $company, string $evidence): never {
            Gate::authorize('viewSources', $company);
            abort(404);
        })->name('evidences.image');

        Route::get('exports/{month}.xlsx', function (Company $company, string $month) {
            Gate::authorize('exportExcel', $company);

            return response("Excel export placeholder ({$month})", 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        })
            ->where('month', '[0-9]{4}-(0[1-9]|1[0-2])')
            ->name('exports.excel');

        Route::get('exports/training-data.zip', function (Company $company) {
            Gate::authorize('exportTrainingData', $company);

            return response('Training data placeholder', 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        })->name('exports.training');
    });

require __DIR__.'/settings.php';
