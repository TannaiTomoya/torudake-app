<?php

namespace App\Policies;

use App\Models\Evidence;
use App\Models\User;

class EvidencePolicy
{
    /**
     * 会社のメンバーなら見られる。送るだけの人は自分が送ったものだけ。
     */
    public function view(User $user, Evidence $evidence): bool
    {
        $membership = $user->memberships()
            ->where('company_id', $evidence->company_id)
            ->whereNull('disabled_at')
            ->first();

        if ($membership === null) {
            return false;
        }

        if ($membership->submit_only) {
            return $evidence->uploaded_by === $user->id;
        }

        return true;
    }
}
