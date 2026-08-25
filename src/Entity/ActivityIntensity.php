<?php

namespace App\Entity;

enum ActivityIntensity: string
{
    case Light = 'light';
    case Medium = 'medium';
    case Strong = 'strong';
}
