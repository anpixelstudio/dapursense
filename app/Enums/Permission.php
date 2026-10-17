<?php

namespace App\Enums;

enum Permission: string
{
    case ManageOrganization = 'manage_organization';
    case ManageMembers = 'manage_members';
    case ManageLocations = 'manage_locations';
    case ManageSources = 'manage_sources';
    case ManageRules = 'manage_rules';
    case UploadVideo = 'upload_video';
    case RunAnalysis = 'run_analysis';
    case ReviewEvents = 'review_events';
    case HoldEvidence = 'hold_evidence';
    case ReopenEvents = 'reopen_events';
    case GenerateReports = 'generate_reports';
    case DownloadReports = 'download_reports';
    case ViewData = 'view_data';
}
