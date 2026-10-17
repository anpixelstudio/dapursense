<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UptimeController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\ZoneController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified.optional', 'tenant:optional'])->group(function () {
    Route::get('organizations/none', [OrganizationController::class, 'none'])->name('organizations.none');
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')->name('invitations.accept');
});

Route::middleware(['auth', 'verified.optional', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('monitor', MonitorController::class)->name('monitor');
    Route::post('tenants/switch', [OrganizationController::class, 'switch'])->name('tenants.switch');

    Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
    Route::post('properties', [LocationController::class, 'storeProperty'])->name('properties.store');
    Route::put('properties/{property}', [LocationController::class, 'updateProperty'])->name('properties.update');
    Route::delete('properties/{property}', [LocationController::class, 'destroyProperty'])->name('properties.destroy');
    Route::post('kitchens', [LocationController::class, 'storeKitchen'])->name('kitchens.store');
    Route::put('kitchens/{kitchen}', [LocationController::class, 'updateKitchen'])->name('kitchens.update');
    Route::delete('kitchens/{kitchen}', [LocationController::class, 'destroyKitchen'])->name('kitchens.destroy');

    Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
    Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
    Route::put('sources/{source}', [SourceController::class, 'update'])->name('sources.update');
    Route::delete('sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');

    Route::get('uptime', [UptimeController::class, 'index'])->name('uptime.index');
    Route::post('devices', [UptimeController::class, 'store'])->name('devices.store');
    Route::put('devices/{device}', [UptimeController::class, 'update'])->name('devices.update');
    Route::post('devices/{device}/token', [UptimeController::class, 'rotateToken'])->name('devices.token');
    Route::delete('devices/{device}', [UptimeController::class, 'destroy'])->name('devices.destroy');

    Route::post('sources/{source}/zones', [ZoneController::class, 'store'])->name('zones.store');
    Route::put('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');
    Route::delete('zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
    Route::post('zones/{zone}/duplicate', [ZoneController::class, 'duplicate'])->name('zones.duplicate');

    Route::get('videos', [VideoController::class, 'index'])->name('videos.index');
    Route::post('videos', [VideoController::class, 'store'])->name('videos.store');
    Route::get('videos/{video}', [VideoController::class, 'show'])->name('videos.show');
    Route::get('videos/{video}/status', [VideoController::class, 'status'])->name('videos.status');
    Route::patch('videos/{video}', [VideoController::class, 'update'])->name('videos.update');
    Route::delete('videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');
    Route::get('videos/{video}/preview', [VideoController::class, 'preview'])->name('videos.preview');
    Route::get('videos/{video}/reference-frame', [VideoController::class, 'referenceFrame'])->name('videos.reference');

    Route::post('videos/{video}/analyses', [AnalysisController::class, 'store'])->name('analyses.store');
    Route::get('analyses/{run}', [AnalysisController::class, 'show'])->name('analyses.show');
    Route::get('analyses/{run}/status', [AnalysisController::class, 'status'])->name('analyses.status');
    Route::post('analyses/{run}/cancel', [AnalysisController::class, 'cancel'])->name('analyses.cancel');
    Route::get('analyses/{run}/observations', [AnalysisController::class, 'observations'])->name('analyses.observations');

    Route::get('activities', [ActivityController::class, 'index'])->name('activities.index');

    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::patch('events/{event}/review', [EventController::class, 'review'])->name('events.review');
    Route::post('events/{event}/hold', [EventController::class, 'hold'])->name('events.hold');
    Route::get('events/{event}/media/{media}', [EventController::class, 'media'])->name('events.media');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');
    Route::post('report-schedules', [ReportController::class, 'storeSchedule'])->name('report-schedules.store');
    Route::delete('report-schedules/{schedule}', [ReportController::class, 'destroySchedule'])->name('report-schedules.destroy');

    Route::get('rules', [RuleController::class, 'index'])->name('rules.index');
    Route::post('rules', [RuleController::class, 'store'])->name('rules.store');
    Route::post('rules/{ruleSet}/revisions', [RuleController::class, 'publish'])->name('rules.publish');

    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::post('members/invitations', [MemberController::class, 'invite'])->name('members.invite');
    Route::delete('members/invitations/{invitation}', [MemberController::class, 'revokeInvitation'])->name('members.invitations.destroy');
    Route::patch('members/{membership}', [MemberController::class, 'update'])->name('members.update');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('system', SystemController::class)->name('system.index');
});

require __DIR__.'/settings.php';
