<?php

namespace App\Enums;

enum TransactionType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Capture = 'capture';
    case Release = 'release';
}
