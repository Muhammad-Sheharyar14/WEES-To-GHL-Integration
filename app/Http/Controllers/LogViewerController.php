<?php

namespace App\Http\Controllers;

use App\Models\WebhookLog;
use Illuminate\Http\Request;

class LogViewerController extends Controller
{
    /**
     * Display recent webhook and trigger logs UI
     */
    public function index(Request $request)
    {
        $source     = $request->query('source');
        $locationId = $request->query('location_id');
        $email      = $request->query('email');
        $search     = $request->query('search');

        $query = WebhookLog::latest();

        if ($source) {
            $query->where('source', $source);
        }
        if ($locationId) {
            $query->where('location_id', $locationId);
        }
        if ($email) {
            $query->where('email', 'like', '%' . trim($email) . '%');
        }
        if ($search) {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed) {
                $q->where('email', 'like', "%{$trimmed}%")
                  ->orWhere('location_id', 'like', "%{$trimmed}%")
                  ->orWhere('event_type', 'like', "%{$trimmed}%");
            });
        }

        $logs = $query->paginate(25)->withQueryString();

        return view('logs', [
            'logs'       => $logs,
            'source'     => $source,
            'locationId' => $locationId,
            'email'      => $email,
            'search'     => $search,
        ]);
    }

    /**
     * Return recent logs via JSON API for live UI / inspection
     */
    public function getLogs(Request $request)
    {
        $limit      = min((int)($request->query('limit', 20)), 100);
        $source     = $request->query('source');
        $locationId = $request->query('location_id');
        $email      = $request->query('email');
        $search     = $request->query('search');

        $query = WebhookLog::latest();

        if ($source) {
            $query->where('source', $source);
        }
        if ($locationId) {
            $query->where('location_id', $locationId);
        }
        if ($email) {
            $query->where('email', 'like', '%' . trim($email) . '%');
        }
        if ($search) {
            $trimmed = trim($search);
            $query->where(function ($q) use ($trimmed) {
                $q->where('email', 'like', "%{$trimmed}%")
                  ->orWhere('location_id', 'like', "%{$trimmed}%")
                  ->orWhere('event_type', 'like', "%{$trimmed}%");
            });
        }

        $logs = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'count'   => $logs->count(),
            'logs'    => $logs,
        ]);
    }
}
