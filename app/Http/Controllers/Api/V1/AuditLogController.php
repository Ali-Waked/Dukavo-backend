<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The audit trail.
 *
 * `audit_logs` is intentionally *not* globally tenant scoped: platform level
 * events (a suspended tenant, a role change) belong to no tenant and must stay
 * visible to platform operators. Because of that, this controller filters by
 * tenant explicitly instead of relying on a global scope.
 */
class AuditLogController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PermissionService $permissions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = AuditLog::query()
            ->with(['user', 'auditable'])
            ->when(
                $request->filled('action'),
                fn ($query) => $query->where('action', $request->string('action')->toString()),
            )
            ->when(
                $request->filled('user_id'),
                fn ($query) => $query->where('user_id', $request->integer('user_id')),
            )
            ->when(
                $request->filled('auditable_type') && $request->filled('auditable_id'),
                fn ($query) => $query
                    ->where('auditable_type', $request->string('auditable_type')->toString())
                    ->where('auditable_id', $request->integer('auditable_id')),
            )
            ->when(
                $request->filled('from') && $request->filled('until'),
                fn ($query) => $query->whereBetween(
                    'created_at',
                    [$request->date('from')->startOfDay(), $request->date('until')->endOfDay()],
                ),
            )
            ->when(! $this->isPlatform($request), fn ($query) => $query->where('tenant_id', $this->tenantId()))
            // Platform operators see tenant rows by default; `platform=1` includes
            // the tenant-less events such as a suspension.
            ->when(
                $this->isPlatform($request) && ! $request->boolean('platform'),
                fn ($query) => $query->whereNotNull('tenant_id'),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100))
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        if (! $this->isPlatform(request()) && $auditLog->tenant_id !== $this->tenantId()) {
            abort(404);
        }

        return new AuditLogResource($auditLog->load(['user', 'auditable']));
    }

    /**
     * The distinct actions, so a filter UI does not have to hard-code the enum.
     */
    public function actions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => array_map(
                fn (AuditAction $action): array => [
                    'value' => $action->value,
                    'label' => $action->label(),
                ],
                AuditAction::cases(),
            ),
        ]);
    }

    /**
     * Audit rows are immutable. The request is refused rather than silently
     * ignored, so an attempt is visible rather than mysterious.
     */
    public function destroy(AuditLog $auditLog): Response
    {
        abort(405, 'Audit entries cannot be deleted.');
    }

    /**
     * Platform operators may read tenant-less events; a tenant user may only read
     * the rows belonging to the tenant it currently has selected.
     */
    private function isPlatform(Request $request): bool
    {
        $user = $request->user();

        return $this->permissions->isSuperAdmin($user)
            || $this->permissions->hasPlatformRole($user, 'dukavo-admin');
    }

    private function tenantId(): int
    {
        return $this->tenants->required()->getKey();
    }
}
