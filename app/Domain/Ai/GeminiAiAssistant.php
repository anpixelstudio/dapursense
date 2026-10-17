<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls Google Generative Language API (AI Studio key).
 * No Composer SDK required — ready as soon as GEMINI_API_KEY is set.
 */
class GeminiAiAssistant implements AiAssistant
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $endpointBase,
    ) {}

    public function provider(): string
    {
        return 'gemini';
    }

    public function summarizeEvent(array $event, string $locale = 'en'): string
    {
        $locale = AiPrompts::normalizeLocale($locale);

        return $this->generate(
            system: AiPrompts::summarizeSystem($locale),
            user: AiPrompts::summarizeUserPayload($event, $locale),
            temperature: 0.2,
            maxTokens: 768,
        );
    }

    public function chatSop(string $message, array $history = [], ?string $sopContext = null, string $locale = 'en'): string
    {
        $locale = AiPrompts::normalizeLocale($locale);
        $message = AiSafety::sanitizeUserText($message);

        if (AiSafety::looksLikeJailbreak($message)) {
            return AiSafety::refusalMessage('chat');
        }

        $safeHistory = [];
        foreach ($history as $turn) {
            $content = AiSafety::sanitizeUserText((string) ($turn['content'] ?? ''), 4000);
            if ($content === '' || AiSafety::looksLikeJailbreak($content)) {
                continue;
            }
            $safeHistory[] = [
                'role' => ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        $transcript = collect($safeHistory)
            ->map(fn (array $turn) => strtoupper($turn['role']).': '.$turn['content'])
            ->implode("\n");

        $user = trim(($transcript !== '' ? $transcript."\n" : '').'USER: '.$message);

        return $this->generate(
            system: AiPrompts::chatSystem($sopContext, $locale),
            user: $user,
            temperature: 0.35,
            maxTokens: 1024,
        );
    }

    public function dailyReport(array $stats, string $focus = 'operations', string $locale = 'en'): string
    {
        $locale = AiPrompts::normalizeLocale($locale);
        $focus = in_array($focus, ['operations', 'safety', 'throughput'], true)
            ? $focus
            : 'operations';

        // Report has no free-form user channel — only server stats + locked preset.
        return $this->generate(
            system: AiPrompts::reportSystem($locale),
            user: AiPrompts::reportUserPayload($stats, $focus, $locale),
            temperature: 0.15,
            maxTokens: 900,
        );
    }

    private function generate(string $system, string $user, float $temperature = 0.4, int $maxTokens = 1024): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        $url = rtrim($this->endpointBase, '/').'/models/'.$this->model.':generateContent';

        try {
            $response = Http::timeout(60)
                ->withQueryParameters(['key' => $this->apiKey])
                ->acceptJson()
                ->post($url, [
                    'system_instruction' => [
                        'parts' => [['text' => $system]],
                    ],
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $user]]],
                    ],
                    'generationConfig' => [
                        'temperature' => $temperature,
                        'maxOutputTokens' => $maxTokens,
                    ],
                    'safetySettings' => [
                        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                    ],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('Gemini request failed: '.$e->response?->json('error.message', $e->getMessage()), 0, $e);
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini returned empty text.');
        }

        return trim($text);
    }
}
