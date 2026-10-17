<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('notifications/index', [
            'notifications' => $request->user()->notifications()
                ->where('data->tenant_id', $this->tenantId())
                ->limit(50)->get(['id', 'data', 'read_at', 'created_at']),
        ]);
    }

    public function markRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->where('data->tenant_id', $this->tenantId())->update(['read_at' => now()]);

        return back();
    }
}
