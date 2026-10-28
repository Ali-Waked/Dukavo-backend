<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The business events behind one conversation.
 *
 * Messages are a separate, customer-visible stream. This endpoint exposes the
 * team-facing trail — assignment and status changes, notes, outbound sends —
 * so the inbox can show who did what without reading the audit log for the
 * whole tenant.
 *
 * `audit_logs` has no tenant global scope because it also holds platform-level
 * events, so the tenant is filtered explicitly here as well as by the route
 * model binding that already guarantees the conversation belongs to it.
 */
class ConversationEventController extends Controller
{
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $events = AuditLog::query()
            ->with('user')
            ->where('tenant_id', $conversation->tenant_id)
            ->where('auditable_type', $conversation->getMorphClass())
            ->where('auditable_id', $conversation->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 30), 100))
            ->withQueryString();

        return AuditLogResource::collection($events);
    }
}
