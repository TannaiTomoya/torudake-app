<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $tannai = Company::firstOrCreate(
            ['name' => '丹内建機（デモ）'],
            [
                'fiscal_year_end_month' => 7,
                'bookkeeping_start_date' => '2026-08-01',
                'is_demo' => true,
            ],
        );
        CompanyDefaultSeeder::seedForCompany($tannai);

        $koumuten = Company::firstOrCreate(
            ['name' => 'デモ工務店'],
            [
                'fiscal_year_end_month' => 3,
                'bookkeeping_start_date' => '2026-04-01',
                'is_demo' => true,
            ],
        );
        CompanyDefaultSeeder::seedForCompany($koumuten);

        $this->seedMember($tannai, '社長', 'owner@example.com', Role::Owner, false);
        $this->seedMember($tannai, '経理担当', 'editor@example.com', Role::Editor, false);
        $this->seedMember($tannai, '現場担当', 'submitter@example.com', Role::Editor, true);
        $this->seedMember($koumuten, '他社社長', 'other@example.com', Role::Owner, false);

        $this->command?->info(sprintf(
            'ログイン：owner@ / editor@ / submitter@ / other@example.com、パスワード %s、2段階認証の鍵 %s（認証アプリに手入力）、復旧コード %s',
            UserFactory::DEV_PASSWORD,
            UserFactory::DEV_TWO_FACTOR_SECRET,
            UserFactory::DEV_RECOVERY_CODE,
        ));
    }

    private function seedMember(Company $company, string $name, string $email, Role $role, bool $submitOnly): void
    {
        $existingUser = User::where('email', $email)->first();

        $user = $existingUser ?? User::factory()->withTwoFactor()->create([
            'name' => $name,
            'email' => $email,
        ]);

        /** @var Membership|null $membership */
        $membership = Membership::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            $membership = new Membership;
        }

        $membership->forceFill([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'submit_only' => $submitOnly,
            'disabled_at' => null,
        ])->save();
    }
}
