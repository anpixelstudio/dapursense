<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    private const SECRET_KEYS = ['password', 'token', 'secret', 'connection', 'encrypted_connection', 'api_key', 'rtsp_url'];

    /** @param array<string, mixed> $changes */
    public static function log(string $action, ?Model $subject = null, array $changes = [], ?string $tenantId = null): void
    {
        AuditLog::query()->create([
            'tenant_id' => $tenantId ?? $subject?->getAttribute('tenant_id') ?? app(TenantContext::class)->id(),
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'redacted_changes' => self::redact($changes),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && collect(self::SECRET_KEYS)->contains(fn ($s) => str_contains(strtolower($key), $s))) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
