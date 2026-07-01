<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LyraSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminLyraSessionController extends Controller
{
    private LyraSessionService $sessionService;

    public function __construct(LyraSessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['intent', 'city', 'dateFrom', 'dateTo', 'userType', 'minMessages']);
        $result = $this->sessionService->getSessions($filters);

        return response()->json([
            'success' => true,
            'data'    => $result['sessions'],
            'meta'    => $result['meta'],
        ]);
    }

    public function show($id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->sessionService->getSession($id),
        ]);
    }

    public function flag($id, Request $request): JsonResponse
    {
        $request->validate(['flag' => 'required|string']);
        $this->sessionService->flagSession($id, $request->flag);

        return response()->json([
            'success' => true,
            'message' => 'Session flagged successfully',
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $filters = $request->all();
        $format  = $request->query('format', 'csv');
        $data    = $this->sessionService->exportSessions($filters, $format);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}
