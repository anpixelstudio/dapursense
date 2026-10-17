<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Supervisor = 'supervisor';
    case Operator = 'operator';
    case Viewer = 'viewer';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Supervisor => [
                Permission::ManageLocations,
                Permission::ManageSources,
                Permission::ManageRules,
                Permission::UploadVideo,
                Permission::RunAnalysis,
                Permission::ReviewEvents,
                Permission::HoldEvidence,
                Permission::ReopenEvents,
                Permission::GenerateReports,
                Permission::DownloadReports,
                Permission::ViewData,
            ],
            self::Operator => [
                Permission::UploadVideo,
                Permission::ViewData,
            ],
            self::Viewer => [
                Permission::DownloadReports,
                Permission::ViewData,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Supervisor => 'Supervisor',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
        };
    }
}
