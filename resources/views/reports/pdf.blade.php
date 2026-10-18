<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>VERA Report {{ $data['identity']['report_id'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .muted { color: #555; }
        .sim { background: #fef3c7; border: 1px solid #f59e0b; padding: 6px; margin: 8px 0; font-weight: bold; }
        .grid td { border: none; padding: 2px 8px 2px 0; }
    </style>
</head>
<body>
    <h1>VERA Anomaly Report</h1>
    <div class="muted">{{ $data['identity']['organization'] }} · Period {{ $data['identity']['period']['from'] }} to {{ $data['identity']['period']['to'] }} · Timezone {{ $data['identity']['timezone'] }}</div>

    @if ($data['identity']['contains_simulation'])
        <div class="sim">SIMULATION — this report includes simulation-mode results that are not real detections.</div>
    @endif

    <h2>Identity</h2>
    <table class="grid">
        <tr><td>Report ID</td><td>{{ $data['identity']['report_id'] }}</td></tr>
        <tr><td>Generated</td><td>{{ $data['identity']['generated_at'] }}</td></tr>
        <tr><td>Data cutoff</td><td>{{ $data['identity']['cutoff_at'] }}</td></tr>
        <tr><td>App version</td><td>{{ $version }}</td></tr>
    </table>

    <h2>Summary</h2>
    <table>
        <tr><th>Total candidates</th><th>Confirmed</th><th>Resolved</th><th>Dismissed</th><th>Unresolved</th><th>Needs review (PPE not visible)</th><th>System anomalies</th></tr>
        <tr>
            <td>{{ $data['summary']['total_candidates'] }}</td>
            <td>{{ $data['summary']['confirmed'] }}</td>
            <td>{{ $data['summary']['resolved'] }}</td>
            <td>{{ $data['summary']['dismissed'] }}</td>
            <td>{{ $data['summary']['unresolved'] }}</td>
            <td>{{ $data['summary']['review_needed'] }}</td>
            <td>{{ $data['summary']['system'] }}</td>
        </tr>
    </table>
    <p class="muted">Candidates are system detections; only Confirmed/Resolved statuses have been approved by a reviewer. Confidence score is a model score, not a violation probability.</p>

    <h2>Analyses covered</h2>
    <table>
        <tr><th>Run</th><th>Mode</th><th>Source</th><th>Video</th><th>Model</th><th>Rule</th><th>App</th><th>Status</th></tr>
        @forelse ($data['runs'] as $run)
            <tr>
                <td>{{ substr($run['id'], 0, 8) }}</td>
                <td>{{ $run['mode'] === 'simulation' ? 'SIMULATION' : 'Actual' }}</td>
                <td>{{ $run['source'] }}</td>
                <td>{{ $run['video'] }}</td>
                <td>{{ $run['model'] }}</td>
                <td>v{{ $run['rule_revision'] }}</td>
                <td>{{ $run['app_version'] }}</td>
                <td>{{ $run['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan="8">No analyses in this period.</td></tr>
        @endforelse
    </table>

    <h2>Activity (person-seconds)</h2>
    <table>
        <tr><th>Label</th><th>Person-seconds</th><th>Track count</th></tr>
        @forelse ($data['activity']['labels'] as $row)
            <tr><td>{{ $row['label'] }}</td><td>{{ $row['person_seconds'] }}</td><td>{{ $row['tracks'] }}</td></tr>
        @empty
            <tr><td colspan="3">No activity intervals.</td></tr>
        @endforelse
    </table>
    <p class="muted">Person-seconds can exceed video duration when multiple people are present. An unknown label does not mean idle.</p>

    <h2>Required PPE</h2>
    <table>
        <tr><th>Item</th><th>Present (s)</th><th>Missing (s)</th><th>Unknown (s)</th><th>Compliance ratio</th><th>Coverage</th></tr>
        @forelse ($data['ppe'] as $row)
            <tr>
                <td>{{ $row['item'] }}</td>
                <td>{{ $row['present_s'] }}</td>
                <td>{{ $row['missing_s'] }}</td>
                <td>{{ $row['unknown_s'] }}</td>
                <td>{{ $row['compliance_ratio'] !== null ? number_format($row['compliance_ratio'] * 100, 1).'%' : '—' }}</td>
                <td>{{ $row['coverage'] !== null ? number_format($row['coverage'] * 100, 1).'%' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6">No required PPE assessments.</td></tr>
        @endforelse
    </table>
    <p class="muted">Ratio = present / (present + missing). Coverage = assessable duration / relevant duration. Unknown is reported separately.</p>

    <h2>Event details</h2>
    <table>
        <tr><th>Time</th><th>Type</th><th>Severity</th><th>Track</th><th>Duration</th><th>Status</th><th>Reviewer &amp; reason</th><th>Evidence</th></tr>
        @forelse ($data['events'] as $e)
            <tr>
                <td>{{ $e['occurred_at'] ?? ($e['relative_start'] ? 'rel. '.$e['relative_start'] : '—') }}</td>
                <td>{{ $e['type'] }}@if($e['simulation']) (SIMULATION)@endif</td>
                <td>{{ $e['severity'] }}</td>
                <td>{{ $e['track'] ?? '—' }}</td>
                <td>{{ $e['duration_s'] !== null ? $e['duration_s'].' s' : '—' }}</td>
                <td>{{ $e['review_status'] }}</td>
                <td>{{ $e['reviewer'] ?? '—' }} {{ $e['review_reason'] }} {{ $e['action_note'] }}</td>
                <td>snapshot {{ $e['snapshot'] }}, clip {{ $e['clip'] }}</td>
            </tr>
        @empty
            <tr><td colspan="8">No events match the filter.</td></tr>
        @endforelse
    </table>

    <h2>Operations</h2>
    <table class="grid">
        <tr><td>Failed analyses</td><td>{{ $data['operational']['failed_runs'] }}</td></tr>
        <tr><td>Unreadable sources</td><td>{{ $data['operational']['source_unreadable'] }}</td></tr>
        <tr><td>Worker stalls</td><td>{{ $data['operational']['analysis_stalled'] }}</td></tr>
    </table>
</body>
</html>
