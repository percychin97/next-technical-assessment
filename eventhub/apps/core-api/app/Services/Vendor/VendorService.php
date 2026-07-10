<?php

namespace App\Services\Vendor;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

class VendorService
{
    /**
     * Onboard a user as a vendor. Creates vendor profile if not exists.
     */
    public function onboard(User $user, array $data): Vendor
    {
        if ($user->role !== UserRole::Vendor) {
            throw new \App\Exceptions\DomainException('User is not a vendor.');
        }

        if ($user->vendor) {
            throw new \App\Exceptions\ConflictException('Vendor profile already exists.');
        }

        return DB::transaction(function () use ($user, $data) {
            return Vendor::create([
                'user_id'       => $user->id,
                'business_name' => $data['business_name'],
                'kyc_status'    => KycStatus::Pending->value,
            ]);
        });
    }

    /**
     * Update vendor profile. Resets KYC to pending on material changes.
     */
    public function updateProfile(Vendor $vendor, array $data): Vendor
    {
        return DB::transaction(function () use ($vendor, $data) {
            $isMaterialChange = isset($data['business_name'])
                && $data['business_name'] !== $vendor->business_name;

            $updateData = array_filter([
                'business_name' => $data['business_name'] ?? null,
            ], fn ($v) => $v !== null);

            if ($isMaterialChange && $vendor->kyc_status === KycStatus::Verified) {
                $updateData['kyc_status'] = KycStatus::Pending->value;
                $updateData['verified_at'] = null;
            }

            $vendor->update($updateData);

            return $vendor->fresh();
        });
    }
}
