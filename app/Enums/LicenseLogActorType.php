<?php

namespace App\Enums;

enum LicenseLogActorType: string
{
    case ADMIN = 'ADMIN';
    case CLIENT = 'CLIENT';
    case SYSTEM = 'SYSTEM';
}
