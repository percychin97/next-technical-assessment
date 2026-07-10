<?php

namespace App\Repositories\Contracts;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface EventRepositoryInterface
{
    public function find(string $id): ?Event;

    public function findOrFail(string $id): Event;

    public function findByVendor(string $vendorId, array $filters = []): LengthAwarePaginator;

    public function findPublished(array $filters = []): LengthAwarePaginator;

    public function create(array $attributes): Event;

    public function update(Event $event, array $attributes): Event;

    public function softDelete(Event $event): bool;

    public function findUpcomingForReminders(): Collection;
}
