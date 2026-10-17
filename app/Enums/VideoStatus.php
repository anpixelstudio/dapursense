<?php

namespace App\Enums;

enum VideoStatus: string
{
    case Uploading = 'UPLOADING';
    case Validating = 'VALIDATING';
    case Ready = 'READY';
    case Rejected = 'REJECTED';
}
