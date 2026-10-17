<?php

namespace App\Enums;

enum EventCategory: string
{
    case Violation = 'violation';
    case System = 'system';
    case ReviewNeeded = 'review_needed';
}
