<?php

namespace App\Enums;

enum SecurityAnswerResult
{
    case Correct;
    case Wrong;
    case Locked;
}
