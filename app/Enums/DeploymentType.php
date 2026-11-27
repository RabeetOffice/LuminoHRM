<?php

namespace App\Enums;

/** What a deployments row records. Only "update" (the Updates screen button) exists today. */
enum DeploymentType: string
{
    case Update = 'update';

    public function label(): string
    {
        return match ($this) {
            self::Update => 'System update',
        };
    }
}
