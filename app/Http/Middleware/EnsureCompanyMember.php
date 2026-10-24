<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyMember
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $company = $request->route('company');

        if (! $company instanceof Company || $user === null || ! $user->can('member', $company)) {
            abort(404);
        }

        $membership = $user->activeMembershipFor($company);

        if ($membership !== null && $membership->submit_only) {
            $routeName = $request->route()?->getName();
            if (! in_array($routeName, ['company.submit', 'company.evidences.image'], true)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
