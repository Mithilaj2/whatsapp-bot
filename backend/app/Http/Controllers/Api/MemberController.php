<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\TenantMember;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(): JsonResponse
    {
        $members = TenantMember::with('user:id,name,email')->orderBy('created_at')->get();

        return response()->json([
            'members' => $members->map(fn (TenantMember $m) => [
                'id' => $m->id,
                'user' => $m->user?->only(['id', 'name', 'email']),
                'role' => $m->role->value,
                'team_id' => $m->team_id,
                'status' => $m->status,
            ]),
        ]);
    }

    public function update(Request $request, TenantMember $member, AuditLogger $audit): JsonResponse
    {
        $actor = $this->context->member();
        $assignable = array_map(fn (TenantRole $r) => $r->value, $actor->role->assignableRoles());

        $data = $request->validate([
            'role' => ['sometimes', Rule::in($assignable)],
            'team_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('teams', 'id')->where('tenant_id', $this->context->tenantId())],
            'max_chats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
        ]);

        // Admins cannot change an owner, and nobody can demote the last owner.
        if ($member->role === TenantRole::Owner && $actor->role !== TenantRole::Owner) {
            return response()->json(['message' => 'Only an owner can change another owner.'], 403);
        }
        if (isset($data['role']) && $member->role === TenantRole::Owner && $data['role'] !== TenantRole::Owner->value
            && TenantMember::where('role', TenantRole::Owner)->where('status', 'active')->count() <= 1) {
            return response()->json(['message' => 'A business needs at least one owner.'], 422);
        }

        $before = ['role' => $member->role->value, 'team_id' => $member->team_id, 'max_chats' => $member->max_chats];
        $member->update($data);
        $audit->record('member.updated', $member, $before, [
            'role' => $member->role->value, 'team_id' => $member->team_id, 'max_chats' => $member->max_chats,
        ]);

        return response()->json(['member' => $member->only(['id', 'role', 'team_id', 'max_chats'])]);
    }
}
