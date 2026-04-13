<?php

namespace App\Enums;

enum OrderType: string
{
    case DineIn = 'Dine In';
    case TakeAway = 'Take Away';
    case Online = 'Online';
}
