<?php

namespace App\Tracking;

/**
 * One GPS point: a unix timestamp and coordinates in degrees x 10,000,000.
 */
final readonly class Point
{
    public function __construct(
        public int $ts,
        public int $latE7,
        public int $lngE7,
        public ?int $accuracyM = null,
    ) {}

    public function lat(): float
    {
        return $this->latE7 / 1e7;
    }

    public function lng(): float
    {
        return $this->lngE7 / 1e7;
    }

    public static function fromDatabase(object $row): self
    {
        return new self(
            strtotime($row->recorded_at.' UTC'),
            (int) $row->lat_e7,
            (int) $row->lng_e7,
            $row->accuracy_m === null ? null : (int) $row->accuracy_m,
        );
    }

    public function toDatabase(int $repId): array
    {
        return [
            'rep_id' => $repId,
            'recorded_at' => gmdate('Y-m-d H:i:s', $this->ts),
            'lat_e7' => $this->latE7,
            'lng_e7' => $this->lngE7,
            'accuracy_m' => $this->accuracyM,
        ];
    }

    public function toArray(): array
    {
        return [
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', $this->ts),
            'lat' => $this->lat(),
            'lng' => $this->lng(),
            'accuracy' => $this->accuracyM,
        ];
    }
}
