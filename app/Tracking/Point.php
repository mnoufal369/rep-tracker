<?php

declare(strict_types=1);

namespace App\Tracking;

/**
 * One GPS fix in its storage representation: unix seconds + coordinates in 1e-7 degrees.
 */
final readonly class Point
{
    public function __construct(
        public int $ts,
        public int $latE7,
        public int $lngE7,
        public ?int $accuracyM = null,
        public ?int $speedCms = null,
        public ?int $headingDeg = null,
        public ?int $batteryPct = null,
    ) {}

    public function lat(): float
    {
        return $this->latE7 / 1e7;
    }

    public function lng(): float
    {
        return $this->lngE7 / 1e7;
    }

    public static function fromRow(object|array $row): self
    {
        $row = (object) $row;

        return new self(
            ts: is_numeric($row->recorded_at) ? (int) $row->recorded_at : strtotime($row->recorded_at.' UTC'),
            latE7: (int) $row->lat_e7,
            lngE7: (int) $row->lng_e7,
            accuracyM: isset($row->accuracy_m) ? (int) $row->accuracy_m : null,
            speedCms: isset($row->speed_cms) ? (int) $row->speed_cms : null,
            headingDeg: isset($row->heading_deg) ? (int) $row->heading_deg : null,
            batteryPct: isset($row->battery_pct) ? (int) $row->battery_pct : null,
        );
    }

    /** @return array<string, int|string|null> */
    public function toRow(int $repId): array
    {
        return [
            'rep_id' => $repId,
            'recorded_at' => gmdate('Y-m-d H:i:s', $this->ts),
            'lat_e7' => $this->latE7,
            'lng_e7' => $this->lngE7,
            'accuracy_m' => $this->accuracyM,
            'speed_cms' => $this->speedCms,
            'heading_deg' => $this->headingDeg,
            'battery_pct' => $this->batteryPct,
        ];
    }

    /** @return array<string, int|float|string|null> */
    public function toApi(): array
    {
        return [
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', $this->ts),
            'lat' => $this->lat(),
            'lng' => $this->lng(),
            'accuracy' => $this->accuracyM,
            'speed' => $this->speedCms === null ? null : round($this->speedCms / 100, 2),
            'heading' => $this->headingDeg,
            'battery' => $this->batteryPct,
        ];
    }
}
