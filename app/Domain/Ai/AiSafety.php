<?php

namespace App\Domain\Ai;

/**
 * Lightweight prompt-injection / jailbreak heuristics for kitchen Gen AI surfaces.
 * Fail closed: suspicious input never reaches the model as an instruction override.
 */
final class AiSafety
{
    /** @var list<string> */
    private const PATTERNS = [
        'ignore (all |any |the )?(previous|prior|above|earlier) (instructions|prompts|rules)',
        'disregard (all |any |the )?(previous|prior|above) (instructions|prompts|rules)',
        'forget (all |any |the )?(previous|prior|above) (instructions|prompts|rules)',
        'you are now (dan|chatgpt|gpt|claude|llama|uncensored|jailbreak)',
        'act as (dan|developer mode|jailbreak|unrestricted)',
        'developer mode',
        'jailbreak',
        'do anything now',
        'dan mode',
        'system prompt',
        'reveal (your |the )?(system|hidden) (prompt|instructions)',
        'show (me )?(your |the )?(system|hidden) (prompt|instructions)',
        'override (your |the )?(safety|rules|policy|guardrails)',
        'bypass (your |the )?(safety|rules|policy|guardrails|filter)',
        'pretend (you|that) (have no|without) (rules|limits|restrictions)',
        'roleplay as',
        'sudo mode',
        '<\|system\|>',
        '\[system\]',
        '###\s*system',
    ];

    public static function looksLikeJailbreak(string $text): bool
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '');

        if ($normalized === '') {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match('/'.$pattern.'/iu', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function refusalMessage(string $surface = 'assistant'): string
    {
        return match ($surface) {
            'report' => 'I can only generate the kitchen operations report from system statistics. I cannot follow instructions that change my role, ignore rules, or discuss unrelated topics.',
            'summarize' => 'I can only summarize this kitchen CCTV anomaly from the provided event data. I cannot follow jailbreak or role-override instructions.',
            default => 'I can only help with kitchen SOP and DapurSense operations. I cannot ignore my instructions, change roles, or discuss unrelated topics.',
        };
    }

    /**
     * Strip common delimiter / injection wrappers from user text before prompting.
     */
    public static function sanitizeUserText(string $text, int $max = 2000): string
    {
        $clean = strip_tags($text);
        $clean = str_replace(["\0", "\r"], '', $clean);
        $clean = preg_replace('/```+|~~~+/u', '', $clean) ?? $clean;

        return mb_substr(trim($clean), 0, $max);
    }
}
