<?php

namespace App\Enums;

/**
 * 仕訳の行が、取引の中で何の役か。
 * - Counterpart：取引の中身（分類の勘定科目。費用・売上・返済する借入・振替先など）
 * - Payment：お金の出どころ・入り先（支払い元・入金先の勘定科目。振替なら振替元）
 */
enum LineRole: string
{
    case Counterpart = 'counterpart';
    case Payment = 'payment';
}
