<?php

namespace App\Domain\Organization;

class SopTemplate
{
    public const SCHEMA_VERSION = 1;

    public const PPE_ITEMS = ['hair_cover', 'apron', 'glove', 'mask'];

    public const ACTIVITY_LABELS = ['cooking', 'preparing', 'washing', 'cleaning', 'serving', 'unknown'];

    /**
     * Starting point only; every value must be calibrated against labelled kitchen video.
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'sampling_fps' => 3,
            'requirements' => [
                ['zone_type' => 'prep', 'items' => ['hair_cover', 'apron']],
                ['zone_type' => 'stove', 'items' => ['hair_cover', 'apron']],
            ],
            'thresholds' => [
                'min_confidence' => [
                    'person' => 0.5,
                    'hair_cover' => 0.6,
                    'apron' => 0.6,
                    'glove' => 0.6,
                    'mask' => 0.6,
                ],
                'min_visibility' => 0.5,
                'missing_ratio' => 0.8,
                'window_ms' => 5000,
                'max_gap_ms' => 1500,
                'grace_ms' => 2000,
                'recovery_ms' => 3000,
                'cooldown_ms' => 30000,
                'restricted_dwell_ms' => 2000,
                'unassessable_ms' => 15000,
            ],
            'severity' => [
                'ppe_missing' => 'medium',
                'restricted_zone' => 'high',
                'unassessable_ppe' => 'review_needed',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function validationRules(): array
    {
        $items = implode(',', self::PPE_ITEMS);

        return [
            'config' => ['required', 'array'],
            'config.sampling_fps' => ['required', 'numeric', 'min:0.5', 'max:15'],
            'config.requirements' => ['present', 'array'],
            'config.requirements.*.zone_type' => ['required', 'in:prep,stove,sink,cleaning,restricted'],
            'config.requirements.*.items' => ['present', 'array'],
            'config.requirements.*.items.*' => ['in:'.$items],
            'config.thresholds' => ['required', 'array'],
            'config.thresholds.min_confidence' => ['required', 'array'],
            'config.thresholds.min_confidence.*' => ['numeric', 'between:0,1'],
            'config.thresholds.min_visibility' => ['required', 'numeric', 'between:0,1'],
            'config.thresholds.missing_ratio' => ['required', 'numeric', 'between:0.5,1'],
            'config.thresholds.window_ms' => ['required', 'integer', 'min:1000', 'max:120000'],
            'config.thresholds.max_gap_ms' => ['required', 'integer', 'min:0', 'max:60000'],
            'config.thresholds.grace_ms' => ['required', 'integer', 'min:0', 'max:120000'],
            'config.thresholds.recovery_ms' => ['required', 'integer', 'min:0', 'max:120000'],
            'config.thresholds.cooldown_ms' => ['required', 'integer', 'min:0', 'max:3600000'],
            'config.thresholds.restricted_dwell_ms' => ['required', 'integer', 'min:0', 'max:600000'],
            'config.thresholds.unassessable_ms' => ['required', 'integer', 'min:1000', 'max:600000'],
            'config.severity' => ['required', 'array'],
            'config.severity.*' => ['in:low,medium,high,review_needed'],
        ];
    }
}
