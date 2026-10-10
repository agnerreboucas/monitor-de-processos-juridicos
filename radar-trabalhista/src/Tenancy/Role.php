<?php
declare(strict_types=1);

namespace Radar\Tenancy;

enum Role: string
{
    case OfficeAdmin = 'office_admin';
    case Lawyer = 'lawyer';
    case Assistant = 'assistant';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::OfficeAdmin => ['process.view', 'process.create', 'process.update', 'process.archive',
                'members.manage', 'plan.view', 'audit.view', 'notifications.view', 'oab.view', 'oab.manage', 'privacy.manage'],
            self::Lawyer => ['process.view', 'process.create', 'process.update', 'process.archive',
                'plan.view', 'notifications.view', 'oab.view'],
            self::Assistant => ['process.view', 'process.create', 'notifications.view'],
        };
    }
}
