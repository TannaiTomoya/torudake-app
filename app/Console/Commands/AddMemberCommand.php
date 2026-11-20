<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\PasswordSetupMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AddMemberCommand extends Command
{
    protected $signature = 'torudake:add-member {company : 会社IDまたは会社名} {email : メールアドレス} {role : ロール（owner/admin/approver/editor/viewer）} {--name= : 表示名} {--submit-only : 送るだけのメンバーにする}';

    protected $description = '人とメンバーを作成し、パスワード再設定のメールを送る';

    public function handle(PasswordSetupMail $mail): int
    {
        $companyArg = trim((string) $this->argument('company'));
        $email = Str::lower(trim((string) $this->argument('email')));
        $roleArg = trim((string) $this->argument('role'));
        $nameOption = $this->option('name');
        $submitOnly = (bool) $this->option('submit-only');

        $role = Role::tryFrom($roleArg);
        if ($role === null) {
            $this->error("不正なロールです: {$roleArg}");

            return self::FAILURE;
        }

        /** @var Company|null $company */
        $company = Company::query()
            ->where('id', $companyArg)
            ->orWhere('name', $companyArg)
            ->first();

        if ($company === null) {
            $this->error("会社が見つかりません: {$companyArg}");

            return self::FAILURE;
        }

        $displayName = is_string($nameOption) && trim($nameOption) !== ''
            ? trim($nameOption)
            : Str::before($email, '@');

        /** @var array{user: User, membership: Membership} $result */
        $result = DB::transaction(function () use ($company, $email, $displayName, $role, $submitOnly): array {
            /** @var User|null $user */
            $user = User::query()->lockForUpdate()->where('email', $email)->first();

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $displayName,
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                    'email_verified_at' => now(),
                ])->save();
            }

            /** @var Membership|null $membership */
            $membership = Membership::query()
                ->lockForUpdate()
                ->where('company_id', $company->id)
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

            AuditLog::record(
                user: null,
                action: 'member.add',
                subject: $membership,
                before: null,
                after: [
                    'email' => $user->email,
                    'role' => $role->value,
                    'submit_only' => $submitOnly,
                ],
                company: $company,
            );

            return ['user' => $user, 'membership' => $membership];
        });

        // 所属の作成と、案内のメールは別のこと。メールを送れなくても所属は残るので、送り直すだけでよい（#39）
        $this->info("メンバーを追加しました：{$result['user']->email}（{$role->label()}）");

        $sent = $mail->send($result['user']);

        if ($sent->needsRetry()) {
            $this->error($sent->explain($result['user']->email));
            $this->line('メンバーは追加済みです。add-member をやり直す必要はありません。');

            return self::FAILURE;
        }

        $this->info($sent->explain($result['user']->email));

        return self::SUCCESS;
    }
}
