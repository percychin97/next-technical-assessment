<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * A vendor can only update/delete their own events.
     * Admins can update any event.
     */
    public function update(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isVendor() && $user->vendor?->id === $event->vendor_id;
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }

    public function view(?User $user, Event $event): bool
    {
        // Published events are visible to all (including guests)
        if ($event->isPublished()) {
            return true;
        }

        if (!$user) {
            return false;
        }

        // Vendors can see their own draft events
        return $user->isAdmin()
            || ($user->isVendor() && $user->vendor?->id === $event->vendor_id);
    }
}
