<?php

namespace App\Enums;

enum ZoneType: string
{
    case Prep = 'prep';
    case Stove = 'stove';
    case Sink = 'sink';
    case Cleaning = 'cleaning';
    case Restricted = 'restricted';
}
