<?php

namespace App\Support;

use App\Models\Apparatus;

/** One apparatus as a session uses it: how many items, people per item, and how many are booked on it. */
final class ApparatusSlot
{
    public function __construct(
        public readonly Apparatus $apparatus,
        public readonly int $quantity,
        public readonly int $perItem,
        public readonly int $taken,
    ) {}

    public function spots(): int
    {
        return $this->quantity * $this->perItem;
    }

    public function left(): int
    {
        return max(0, $this->spots() - $this->taken);
    }

    public function isFull(): bool
    {
        return $this->left() === 0;
    }
}
