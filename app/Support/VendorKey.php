<?php

namespace App\Support;

/**
 * 店名を比べるための正規化（全角・半角・空白・大文字小文字の違いを同じにする）。
 * `journal_entries.vendor_key` に入れ、同じ店の履歴を索引で引く（#54）。
 */
final class VendorKey
{
    public static function of(?string $vendor): ?string
    {
        if ($vendor === null) {
            return null;
        }

        $key = mb_strtolower((string) preg_replace('/\s+/u', '', mb_convert_kana($vendor, 'asKV')));

        return $key === '' ? null : mb_substr($key, 0, 100);
    }
}
