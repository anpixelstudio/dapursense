<?php

namespace App\Domain\Ai;

/**
 * Generative-AI surface for hackathon / GenAI features.
 * Swap stub → Gemini with AI_PROVIDER=gemini and GEMINI_API_KEY.
 */
interface AiAssistant
{
    public function provider(): string;

    /** @param array<string, mixed> $event */
    public function summarizeEvent(array $event, string $locale = 'en'): string;

    /** @param list<array{role: string, content: string}> $history */
    public function chatSop(string $message, array $history = [], ?string $sopContext = null, string $locale = 'en'): string;

    /**
     * Locked report generator — stats only; optional focus preset (not free-form user prompt).
     *
     * @param  array<string, mixed>  $stats
     * @param  'operations'|'safety'|'throughput'  $focus
     */
    public function dailyReport(array $stats, string $focus = 'operations', string $locale = 'en'): string;
}
