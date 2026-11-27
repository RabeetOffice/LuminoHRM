<?php

namespace App\Enums;

/** The state of one system update. */
enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Running => 'Running',
            self::Success => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Success || $this === self::Failed;
    }
}
