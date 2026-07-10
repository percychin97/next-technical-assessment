<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Vendor;
use App\Enums\KycStatus;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminVendorController extends Controller
{
    /**
     * GET /api/v1/admin/vendors — List vendors (default: pending KYC).
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('kyc_status');
        
        $query = Vendor::with('user:id,email');
        if ($status && $status !== 'all') {
            $query->where('kyc_status', $status);
        }
        
        $vendors = $query->orderBy('created_at', 'desc')->paginate(50);

        return ApiResponse::success($vendors);
    }

    /**
     * POST /api/v1/admin/vendors/{id}/approve
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);

        if ($vendor->kyc_status === KycStatus::Verified->value) {
            return ApiResponse::error('Vendor is already verified', 422);
        }

        DB::transaction(function () use ($vendor, $request) {
            $previous = $vendor->kyc_status;
            $vendor->update([
                'kyc_status'  => KycStatus::Verified->value,
                'verified_at' => now(),
                'kyc_rejection_reason' => null,
            ]);

            AuditLog::create([
                'entity_type'     => 'vendor',
                'entity_id'       => $vendor->id,
                'action'          => 'kyc_approved',
                'previous_status' => $previous,
                'new_status'      => KycStatus::Verified->value,
                'actor_user_id'   => $request->user()->id,
                'correlation_id'  => (string) Str::uuid(),
                'created_at'      => now(),
            ]);
        });

        return ApiResponse::success($vendor->fresh(), 'Vendor approved');
    }

    /**
     * POST /api/v1/admin/vendors/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10']]);

        $vendor = Vendor::findOrFail($id);

        DB::transaction(function () use ($vendor, $request) {
            $previous = $vendor->kyc_status;
            $vendor->update([
                'kyc_status'          => KycStatus::Rejected->value,
                'kyc_rejection_reason' => $request->input('reason'),
                'verified_at'         => null,
            ]);

            AuditLog::create([
                'entity_type'     => 'vendor',
                'entity_id'       => $vendor->id,
                'action'          => 'kyc_rejected',
                'previous_status' => $previous,
                'new_status'      => KycStatus::Rejected->value,
                'after_state'     => ['reason' => $request->input('reason')],
                'actor_user_id'   => $request->user()->id,
                'correlation_id'  => (string) Str::uuid(),
                'created_at'      => now(),
            ]);
        });

        return ApiResponse::success($vendor->fresh(), 'Vendor rejected');
    }
}
