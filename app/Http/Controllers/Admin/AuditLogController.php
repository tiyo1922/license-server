<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LicenseLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Display a paginated, filterable listing of immutable audit log entries.
     */
    public function index(Request $request): View
    {
        $query = LicenseLog::query()
            ->with(['application', 'license', 'actorUser'])
            ->latest('id');

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('application_id')) {
            $query->where('application_id', $request->input('application_id'));
        }

        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->input('actor_type'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                    ->orWhere('user_agent', 'like', "%{$search}%")
                    ->orWhere('actor_key_id', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(20)->withQueryString();
        $applications = Application::orderBy('name')->get();
        $events = LicenseLogEvent::cases();
        $actorTypes = LicenseLogActorType::cases();

        return view('admin.audit-logs.index', compact(
            'logs',
            'applications',
            'events',
            'actorTypes'
        ));
    }
}
