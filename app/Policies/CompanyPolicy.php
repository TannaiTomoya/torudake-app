<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;

class CompanyPolicy
{
    public function member(User $user, Company $company): bool
    {
        return $this->activeMembership($user, $company) !== null;
    }

    public function viewHome(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null || $membership->submit_only) {
            return false;
        }

        return true;
    }

    public function submit(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null) {
            return false;
        }

        if ($membership->submit_only) {
            return true;
        }

        return $membership->role->canSubmit();
    }

    public function viewSources(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null || $membership->submit_only) {
            return false;
        }

        return true;
    }

    public function viewSource(User $user, Company $company, ?int $submittedByUserId = null): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null) {
            return false;
        }

        if ($membership->submit_only) {
            return $submittedByUserId !== null && $submittedByUserId === $user->id;
        }

        return true;
    }

    public function editDraft(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null || $membership->submit_only) {
            return false;
        }

        return $membership->role->canEditDraft();
    }

    public function exportExcel(User $user, Company $company): bool
    {
        return $this->viewSources($user, $company);
    }

    public function viewLive(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null || $membership->submit_only) {
            return false;
        }

        return $membership->role->canViewLiveAndAi();
    }

    public function viewAi(User $user, Company $company): bool
    {
        return $this->viewLive($user, $company);
    }

    public function exportTrainingData(User $user, Company $company): bool
    {
        return $this->viewLive($user, $company);
    }

    public function viewOwner(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null || $membership->submit_only) {
            return false;
        }

        return $membership->role->canViewOwner();
    }

    public function importReports(User $user, Company $company): bool
    {
        return $this->viewOwner($user, $company);
    }

    public function returnToBookkeeper(User $user, Company $company): bool
    {
        return $this->viewOwner($user, $company);
    }

    public function manageSettings(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null || $membership->submit_only) {
            return false;
        }

        return $membership->role->canManageSettings();
    }

    private function activeMembership(User $user, Company $company): ?Membership
    {
        return $user->activeMembershipFor($company);
    }
}
