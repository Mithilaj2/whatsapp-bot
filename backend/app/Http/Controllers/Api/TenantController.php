<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /** Another business for the signed-in user, who becomes its owner. */
    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $tenant = $provisioner->create($request->user(), $data);

        return response()->json(['tenant' => $this->present($tenant)], 201);
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'tenant' => $this->present($this->context->tenant()),
            'role' => $this->context->member()->role->value,
        ]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $tenant = $this->context->tenant();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // GSTIN: 2-digit state code, 10-character PAN, entity number, Z, checksum.
            'gstin' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        $before = $tenant->only(array_keys($data));
        $tenant->update($data);
        $audit->record('tenant.updated', $tenant, $before, $tenant->only(array_keys($data)));

        return response()->json(['tenant' => $this->present($tenant)]);
    }

    private function present($tenant): array
    {
        return $tenant->only(['id', 'name', 'legal_name', 'gstin', 'timezone', 'status', 'data_region']);
    }
}
