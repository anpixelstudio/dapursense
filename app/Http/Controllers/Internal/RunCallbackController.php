<?php

namespace App\Http\Controllers\Internal;

use App\Domain\Analysis\IngestionResult;
use App\Domain\Analysis\IngestionService;
use App\Domain\Organization\SopTemplate;
use App\Http\Controllers\Controller;
use App\Models\AnalysisRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RunCallbackController extends Controller
{
    public const ACTIVITY_LABELS = [
        'cooking', 'preparing', 'washing', 'cleaning', 'serving', 'unknown', 'stationary',
        'in_prep_zone', 'in_stove_zone', 'in_sink_zone', 'in_cleaning_zone', 'in_restricted_zone', 'outside_zones',
    ];

    public const EVENT_TYPES = [
        'ppe_missing', 'restricted_zone', 'unassessable_ppe', 'source_unreadable', 'observation_lost',
    ];

    public function __construct(private IngestionService $ingestion) {}

    public function batch(Request $request): JsonResponse
    {
        $run = $this->run($request);
        $payload = $request->validate($this->batchRules($run));

        return $this->respond($this->ingestion->ingest($run, $payload, $request->getContent()));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'attempt' => ['required', 'integer', 'min:1'],
            'processed_ms' => ['nullable', 'integer', 'min:0'],
            'phase' => ['nullable', 'string', 'in:decoding,inference,evidence,finalizing'],
        ]);

        return $this->respond($this->ingestion->heartbeat($this->run($request), $payload));
    }

    public function complete(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'attempt' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:succeeded,failed,cancelled'],
            'final_batch_sequence' => ['required', 'integer', 'min:0'],
            'error_code' => ['nullable', 'string', 'max:64'],
            'error_message' => ['nullable', 'string', 'max:1000'],
            'manifest' => ['nullable', 'array'],
        ]);

        return $this->respond($this->ingestion->complete($this->run($request), $payload));
    }

    public function health(): JsonResponse
    {
        DB::select('select 1');

        return response()->json(['ok' => true, 'contract_version' => config('kitchen.worker.contract_version')]);
    }

    private function run(Request $request): AnalysisRun
    {
        return $request->attributes->get('run');
    }

    private function respond(IngestionResult $result): JsonResponse
    {
        return response()->json($result->body, $result->status);
    }

    /** @return array<string, mixed> */
    private function batchRules(AnalysisRun $run): array
    {
        $zoneIds = collect(data_get($run->input_snapshot, 'zones', []))->pluck('id')->all();
        $prefix = $run->directory().'/';

        $mediaKey = function (string $attribute, mixed $value, \Closure $fail) use ($prefix) {
            if ($value !== null && (! str_starts_with($value, $prefix) || str_contains($value, '..'))) {
                $fail('Media key must be under the run directory.');
            }
        };

        return [
            'schema_version' => ['required', 'integer', Rule::in([config('kitchen.worker.contract_version')])],
            'attempt' => ['required', 'integer', 'min:1'],
            'batch_sequence' => ['required', 'integer', 'min:1'],
            'model_sha256' => ['required', 'string', Rule::in([$run->model_sha256])],
            'rules_sha256' => ['required', 'string', Rule::in([$run->rules_sha256])],
            'progress' => ['nullable', 'array'],
            'progress.processed_ms' => ['nullable', 'integer', 'min:0'],
            'progress.phase' => ['nullable', 'in:decoding,inference,evidence,finalizing'],

            'tracks' => ['present', 'array', 'max:2000'],
            'tracks.*.track_key' => ['required', 'string', 'max:32'],
            'tracks.*.first_ms' => ['required', 'integer', 'min:0'],
            'tracks.*.last_ms' => ['required', 'integer', 'gte:tracks.*.first_ms'],

            'activity_segments' => ['present', 'array', 'max:5000'],
            'activity_segments.*.track_key' => ['required', 'string', 'max:32'],
            'activity_segments.*.zone_id' => ['nullable', Rule::in($zoneIds)],
            'activity_segments.*.label' => ['required', Rule::in(self::ACTIVITY_LABELS)],
            'activity_segments.*.start_ms' => ['required', 'integer', 'min:0'],
            'activity_segments.*.end_ms' => ['required', 'integer', 'gte:activity_segments.*.start_ms'],
            'activity_segments.*.score' => ['nullable', 'numeric', 'between:0,1'],
            'activity_segments.*.evidence' => ['nullable', 'array'],

            'ppe_assessments' => ['present', 'array', 'max:5000'],
            'ppe_assessments.*.track_key' => ['required', 'string', 'max:32'],
            'ppe_assessments.*.item_code' => ['required', Rule::in(SopTemplate::PPE_ITEMS)],
            'ppe_assessments.*.required' => ['required', 'boolean'],
            'ppe_assessments.*.state' => ['required', 'in:present,missing,unknown'],
            'ppe_assessments.*.start_ms' => ['required', 'integer', 'min:0'],
            'ppe_assessments.*.end_ms' => ['required', 'integer', 'gte:ppe_assessments.*.start_ms'],
            'ppe_assessments.*.score' => ['nullable', 'numeric', 'between:0,1'],
            'ppe_assessments.*.visibility' => ['nullable', 'numeric', 'between:0,1'],
            'ppe_assessments.*.reason' => ['nullable', 'string', 'max:64'],

            'events' => ['present', 'array', 'max:1000'],
            'events.*.type' => ['required', Rule::in(self::EVENT_TYPES)],
            'events.*.category' => ['required', 'in:violation,system,review_needed'],
            'events.*.severity' => ['required', 'in:low,medium,high,review_needed,system'],
            'events.*.lifecycle' => ['required', 'in:active,closed,observation_lost'],
            'events.*.track_key' => ['nullable', 'string', 'max:32'],
            'events.*.episode_start_ms' => ['required', 'integer', 'min:0'],
            'events.*.first_ms' => ['required', 'integer', 'min:0'],
            'events.*.last_ms' => ['required', 'integer', 'gte:events.*.first_ms'],
            'events.*.peak_score' => ['nullable', 'numeric', 'between:0,1'],
            'events.*.evidence' => ['nullable', 'array'],
            'events.*.evidence.bbox_xyxy_normalized' => ['nullable', 'array', 'size:4'],
            'events.*.evidence.bbox_xyxy_normalized.*' => ['numeric', 'between:0,1'],
            'events.*.media' => ['nullable', 'array', 'max:3'],
            'events.*.media.*.kind' => ['required', 'in:snapshot,clip,overlay'],
            'events.*.media.*.key' => ['nullable', 'string', 'max:255', $mediaKey],
            'events.*.media.*.state' => ['required', 'in:PENDING,READY,FAILED'],
            'events.*.media.*.start_ms' => ['nullable', 'integer', 'min:0'],
            'events.*.media.*.end_ms' => ['nullable', 'integer', 'min:0'],
            'events.*.media.*.checksum' => ['nullable', 'string', 'size:64'],
            'events.*.media.*.error_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
