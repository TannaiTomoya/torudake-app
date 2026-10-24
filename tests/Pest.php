<?php

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\CompanyDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * 勘定科目・分類・口座の初期値つきの会社と、そのメンバーを作る。
 *
 * @return array{company: Company, user: User, membership: Membership}
 */
function createCompanyWithMember(
    Role $role = Role::Owner,
    bool $submitOnly = false,
    bool $withTwoFactor = true,
    ?Company $company = null,
): array {
    if ($company === null) {
        $company = Company::factory()->create(['name' => 'テスト建機']);
        CompanyDefaultSeeder::seedForCompany($company);
    }

    $userFactory = User::factory();
    if ($withTwoFactor) {
        $userFactory = $userFactory->withTwoFactor();
    }
    $user = $userFactory->create();

    $membership = new Membership;
    $membership->forceFill([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => $role,
        'submit_only' => $submitOnly,
        'disabled_at' => null,
    ])->save();

    return ['company' => $company, 'user' => $user, 'membership' => $membership];
}
