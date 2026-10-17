<?php

namespace App\Domain\Events;

use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Models\AnomalyEvent;
use App\Models\EventReview;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    /**
     * @param  array{to_status:string,expected_revision:int,reason_code?:?string,reason?:?string,action_note?:?string}  $input
     *
     * @throws ReviewConflict when another reviewer changed the event first
     */
    public function transition(AnomalyEvent $event, array $input, User $actor, Role $role): AnomalyEvent
    {
        $to = ReviewStatus::from($input['to_status']);
        $from = $event->review_status;

        if (! $from->canTransitionTo($to, $event->category)) {
            throw ValidationException::withMessages([
                'to_status' => "Transisi {$from->value} → {$to->value} tidak diizinkan untuk kategori {$event->category->value}.",
            ])->status(422);
        }

        $this->validateRequirements($from, $to, $input, $role);

        return DB::transaction(function () use ($event, $input, $actor, $from, $to) {
            $nextRevision = $input['expected_revision'] + 1;

            $updated = AnomalyEvent::query()
                ->whereKey($event->id)
                ->where('revision', $input['expected_revision'])
                ->where('review_status', $from->value)
                ->update([
                    'review_status' => $to->value,
                    'revision' => $nextRevision,
                    'last_reviewed_by' => $actor->id,
                    'last_reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new ReviewConflict($event->fresh(['lastReviewer:id,name']));
            }

            EventReview::query()->create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'actor_id' => $actor->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'event_revision' => $nextRevision,
                'reason_code' => $input['reason_code'] ?? null,
                'reason' => $input['reason'] ?? null,
                'action_note' => $input['action_note'] ?? null,
            ]);

            Audit::log('event.reviewed', $event, [
                'from' => $from->value,
                'to' => $to->value,
                'revision' => $nextRevision,
                'reason_code' => $input['reason_code'] ?? null,
            ]);

            return $event->fresh();
        });
    }

    /** @param array<string, mixed> $input */
    private function validateRequirements(ReviewStatus $from, ReviewStatus $to, array $input, Role $role): void
    {
        $errors = [];
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($to === ReviewStatus::Confirmed && $reason === '') {
            $errors['reason'] = 'Alasan wajib diisi untuk konfirmasi.';
        }

        if ($to === ReviewStatus::Dismissed) {
            if (! in_array($input['reason_code'] ?? null, ReviewStatus::DISMISS_REASONS, true)) {
                $errors['reason_code'] = 'Pilih kode alasan penolakan.';
            }
            if ($reason === '') {
                $errors['reason'] = 'Catatan penolakan wajib diisi.';
            }
        }

        if ($to === ReviewStatus::Resolved && trim((string) ($input['action_note'] ?? '')) === '') {
            $errors['action_note'] = 'Catatan tindakan penanganan wajib diisi.';
        }

        if ($from->isReopen($to)) {
            if (! in_array($role, [Role::Owner, Role::Supervisor], true)) {
                $errors['to_status'] = 'Hanya Owner atau Supervisor yang dapat membuka ulang kejadian.';
            }
            if ($reason === '') {
                $errors['reason'] = 'Alasan membuka ulang wajib diisi.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
