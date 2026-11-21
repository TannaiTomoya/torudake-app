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

    /** ホームとナビの表示方針。各画面へのアクセス許可は Policy で判定する。 */
    public function prefersOwnerHome(): bool
    {
        return $this === self::Owner;
    }

    public function canViewLiveAndAi(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canViewOwner(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** 通帳の残高の入力・取り消し（#66・#63 の決定）。approver は版3.0 の名残で、新しい権限は与えない */
    public function canEnterBankBalance(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor], true);
    }

    /** 試算表（画面と Excel）を見る（#65 の決定） */
    public function canViewTrialBalance(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor], true);
    }

    /** 開始仕訳を入れる・直す（#65 の決定）。admin には与えない */
    public function canEnterOpeningEntry(): bool
    {
        return in_array($this, [self::Owner, self::Editor], true);
    }

    /** 取引先への依存の知らせの割合を変える（#75 の決定）。警告のしきい値なので社長だけ。admin は見るだけ */
    public function canManageConcentrationAlert(): bool
    {
        return $this === self::Owner;
    }

    /**
     * 入金と支払いの予定を見る・入れる・直す・済みにする・取り消す（#67 の決定）。
     * 取引先の本当の名前と金額が見えるので、owner と editor だけ。admin にも与えない
     */
    public function canManageSchedules(): bool
    {
        return in_array($this, [self::Owner, self::Editor], true);
    }

    /**
     * 資金繰り表を見る（#68 の決定）。残高と予定の金額から見込みを出すので、owner と editor だけ。admin にも与えない
     */
    public function canViewCashFlow(): bool
    {
        return in_array($this, [self::Owner, self::Editor], true);
    }

    /**
     * 分類のルールを見る・足す・直す・消す・並べ替え・候補から採用する（#69 の決定）。owner と editor だけ。admin にも与えない
     */
    public function canManageCategoryRules(): bool
    {
        return in_array($this, [self::Owner, self::Editor], true);
    }

    public function canManageSettings(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
