<?php

namespace App\Notifications;

use App\Models\AnalysisRun;
use Illuminate\Notifications\Notification;

class EventsNeedReview extends Notification
{
    public function __construct(private AnalysisRun $run, private int $openCount) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->run->tenant_id,
            'kind' => 'events_need_review',
            'title' => $this->openCount.' event(s) need review',
            'body' => ($this->run->isSimulation() ? 'SIMULATION ' : '').'analysis finished for video '.($this->run->video?->original_name ?? '-').'.',
            'url' => route('events.index', ['run_id' => $this->run->id], false),
            'is_simulation' => $this->run->isSimulation(),
        ];
    }
}
