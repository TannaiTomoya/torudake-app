<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Approver = 'approver';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => '社長・責任者',
            self::Admin => '管理者',
            self::Approver => '承認者',
            self::Editor => '経理担当',
            self::Viewer => '閲覧のみ',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Owner => 'brand',
            self::Admin => 'ink',
            self::Approver => 'ok',
            self::Editor => 'ink-sub',
            self::Viewer => 'muted',
        };
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Approver, self::Editor], true);
    }

    public function canEditDraft(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Approver, self::Editor], true);
    }

    public function canApprove(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Approver], true);
    }

    public function canRecordCash(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Approver, self::Editor], true);
    }

    public function canViewClose(): bool
    {
        return true;
    }

    public function canClose(): bool
    {
        return $this === self::Owner;
    }

    public function canViewLiveAndAi(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageSettings(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
