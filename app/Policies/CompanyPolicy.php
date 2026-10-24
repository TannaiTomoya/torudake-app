<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;

class CompanyPolicy
{
    /** 2段階認証がまだの人にも許す。「最初にやること」のカードを出す画面を開くためだけ */
    private const ALLOWED_BEFORE_TWO_FACTOR = ['member', 'viewHome', 'viewSubmit'];

    /**
     * 2段階認証がまだの人は、カードを出す画面を開く以外はすべて拒む（docs/02 §5）。
     * Livewire のアクションにはルートの two-factor がかからないので、ここで確かめる。
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->two_factor_confirmed_at === null && ! in_array($ability, self::ALLOWED_BEFORE_TWO_FACTOR, true)) {
            return false;
        }

        return null;
    }

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

    /**
     * 送る画面を開く。2段階認証がまだなら、カードだけが出る。
     */
    public function viewSubmit(User $user, Company $company): bool
    {
        return $this->canSubmit($user, $company);
    }

    /**
     * 写真を選ぶ・送る（2段階認証が済んだ人だけ。before で確かめる）。
     */
    public function submit(User $user, Company $company): bool
    {
        return $this->canSubmit($user, $company);
    }

    public function viewSources(User $user, Company $company): bool
    {
        $membership = $this->activeMembership($user, $company);

        if ($membership === null || $membership->submit_only) {
            return false;
        }

        return true;
    }

    public function submitEvidence(User $user, Company $company): bool
    {
        return $user->hasVerifiedEmail() && $user->two_factor_confirmed_at !== null && $this->submit($user, $company);
    }

    public function viewHomeData(User $user, Company $company): bool
    {
        return $user->hasVerifiedEmail() && $user->two_factor_confirmed_at !== null && $this->viewHome($user, $company);
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

    private function canSubmit(User $user, Company $company): bool
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

    private function activeMembership(User $user, Company $company): ?Membership
    {
        return $user->activeMembershipFor($company);
    }
}
