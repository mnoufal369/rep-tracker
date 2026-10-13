<?php

declare(strict_types=1);

namespace App\Tracking;

final readonly class IngestResult
{
    /**
     * @param  list<array{index: int, reason: string}>  $rejected
     * @param  list<string>  $days
     */
    public function __construct(
        public int $received,
        public int $stored,
        public int $duplicates,
        public array $rejected,
        public array $days,
    ) {}

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'received' => $this->received,
            'stored' => $this->stored,
            'duplicates' => $this->duplicates,
            'rejected' => $this->rejected,
            'days' => $this->days,
        ];
    }
}
