<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Open = 'OPEN';
    case Acknowledged = 'ACKNOWLEDGED';
    case Confirmed = 'CONFIRMED';
    case Dismissed = 'DISMISSED';
    case Resolved = 'RESOLVED';

    public const DISMISS_REASONS = ['false_detection', 'not_applicable', 'duplicate', 'other'];

    public function canTransitionTo(self $to, EventCategory $category): bool
    {
        return match ([$this, $to]) {
            [self::Open, self::Acknowledged] => true,
            [self::Open, self::Confirmed],
            [self::Acknowledged, self::Confirmed] => $category === EventCategory::Violation,
            [self::Open, self::Dismissed],
            [self::Acknowledged, self::Dismissed] => true,
            [self::Confirmed, self::Resolved] => true,
            [self::Acknowledged, self::Resolved] => $category !== EventCategory::Violation,
            [self::Dismissed, self::Open],
            [self::Resolved, self::Open] => true,
            default => false,
        };
    }

    public function isReopen(self $to): bool
    {
        return $to === self::Open && in_array($this, [self::Dismissed, self::Resolved], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Dismissed, self::Resolved], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Acknowledged => 'Diperiksa',
            self::Confirmed => 'Dikonfirmasi',
            self::Dismissed => 'Ditolak',
            self::Resolved => 'Selesai',
        };
    }
}
