<?php

namespace App\Repositories\Eloquent;

use App\Models\Event;
use App\Repositories\Contracts\EventRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentEventRepository implements EventRepositoryInterface
{
    public function find(string $id): ?Event
    {
        return Event::find($id);
    }

    public function findOrFail(string $id): Event
    {
        return Event::findOrFail($id);
    }

    public function findByVendor(string $vendorId, array $filters = []): LengthAwarePaginator
    {
        return Event::query()
            ->where('vendor_id', $vendorId)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function findPublished(array $filters = []): LengthAwarePaginator
    {
        return Event::query()
            ->where('status', 'published')
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('title', 'ILIKE', "%{$search}%");
            })
            ->orderBy('start_at_utc', 'asc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $attributes): Event
    {
        return Event::create($attributes);
    }

    public function update(Event $event, array $attributes): Event
    {
        $event->update($attributes);
        return $event->fresh();
    }

    public function softDelete(Event $event): bool
    {
        return $event->delete();
    }

    public function findUpcomingForReminders(): Collection
    {
        return Event::query()
            ->whereIn('status', ['published', 'ongoing'])
            ->whereBetween('start_at_utc', [now(), now()->addHours(24)])
            ->get();
    }
}
