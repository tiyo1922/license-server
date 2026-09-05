<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case UNUSED = 'UNUSED';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';
}
