<?php

namespace App\Enums;

enum ApplicationApiKeyStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
}
