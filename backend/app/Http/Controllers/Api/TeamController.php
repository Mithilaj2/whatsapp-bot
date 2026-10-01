<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['teams' => Team::withCount('members')->orderBy('name')->get()]);
    }

    public function store(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('teams')->where('tenant_id', $context->tenantId())],
        ]);

        $team = Team::create($data);
        $audit->record('team.created', $team, after: $data);

        return response()->json(['team' => $team], 201);
    }

    public function destroy(Team $team, AuditLogger $audit): JsonResponse
    {
        $team->delete();
        $audit->record('team.deleted', $team, before: $team->only(['name']));

        return response()->json(null, 204);
    }
}
