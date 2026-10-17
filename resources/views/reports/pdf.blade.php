<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kitchen Intelligence {{ $data['identity']['report_id'] }}</title>
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
    <h1>Laporan Anomali Kitchen Intelligence</h1>
    <div class="muted">{{ $data['identity']['organization'] }} · Periode {{ $data['identity']['period']['from'] }} s/d {{ $data['identity']['period']['to'] }} · Zona waktu {{ $data['identity']['timezone'] }}</div>

    @if ($data['identity']['contains_simulation'])
        <div class="sim">SIMULASI — laporan ini memuat hasil mode simulasi yang bukan hasil deteksi nyata.</div>
    @endif

    <h2>Identitas</h2>
    <table class="grid">
        <tr><td>Report ID</td><td>{{ $data['identity']['report_id'] }}</td></tr>
        <tr><td>Dibuat</td><td>{{ $data['identity']['generated_at'] }}</td></tr>
        <tr><td>Cutoff data</td><td>{{ $data['identity']['cutoff_at'] }}</td></tr>
        <tr><td>Versi aplikasi</td><td>{{ $version }}</td></tr>
    </table>

    <h2>Ringkasan</h2>
    <table>
        <tr><th>Total kandidat</th><th>Dikonfirmasi</th><th>Selesai</th><th>Ditolak</th><th>Belum selesai</th><th>Perlu review (APD tak terlihat)</th><th>Anomali sistem</th></tr>
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
    <p class="muted">Kandidat adalah deteksi sistem; hanya status Dikonfirmasi/Selesai yang telah disetujui reviewer. Skor confidence adalah skor model, bukan probabilitas pelanggaran.</p>

    <h2>Analisis yang dicakup</h2>
    <table>
        <tr><th>Run</th><th>Mode</th><th>Sumber</th><th>Video</th><th>Model</th><th>Rule</th><th>App</th><th>Status</th></tr>
        @forelse ($data['runs'] as $run)
            <tr>
                <td>{{ substr($run['id'], 0, 8) }}</td>
                <td>{{ $run['mode'] === 'simulation' ? 'SIMULASI' : 'Nyata' }}</td>
                <td>{{ $run['source'] }}</td>
                <td>{{ $run['video'] }}</td>
                <td>{{ $run['model'] }}</td>
                <td>v{{ $run['rule_revision'] }}</td>
                <td>{{ $run['app_version'] }}</td>
                <td>{{ $run['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Tidak ada analisis pada periode ini.</td></tr>
        @endforelse
    </table>

    <h2>Aktivitas (person-seconds)</h2>
    <table>
        <tr><th>Label</th><th>Person-seconds</th><th>Jumlah track</th></tr>
        @forelse ($data['activity']['labels'] as $row)
            <tr><td>{{ $row['label'] }}</td><td>{{ $row['person_seconds'] }}</td><td>{{ $row['tracks'] }}</td></tr>
        @empty
            <tr><td colspan="3">Tidak ada interval aktivitas.</td></tr>
        @endforelse
    </table>
    <p class="muted">Person-seconds dapat melebihi durasi video bila ada beberapa orang. Label unknown tidak berarti idle.</p>

    <h2>APD wajib</h2>
    <table>
        <tr><th>Item</th><th>Present (s)</th><th>Missing (s)</th><th>Unknown (s)</th><th>Rasio memenuhi</th><th>Coverage</th></tr>
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
            <tr><td colspan="6">Tidak ada penilaian APD wajib.</td></tr>
        @endforelse
    </table>
    <p class="muted">Rasio = present / (present + missing). Coverage = durasi dapat dinilai / durasi relevan. Unknown dilaporkan terpisah.</p>

    <h2>Rincian kejadian</h2>
    <table>
        <tr><th>Waktu</th><th>Jenis</th><th>Severity</th><th>Track</th><th>Durasi</th><th>Status</th><th>Reviewer &amp; alasan</th><th>Bukti</th></tr>
        @forelse ($data['events'] as $e)
            <tr>
                <td>{{ $e['occurred_at'] ?? ($e['relative_start'] ? 'rel. '.$e['relative_start'] : '—') }}</td>
                <td>{{ $e['type'] }}@if($e['simulation']) (SIMULASI)@endif</td>
                <td>{{ $e['severity'] }}</td>
                <td>{{ $e['track'] ?? '—' }}</td>
                <td>{{ $e['duration_s'] !== null ? $e['duration_s'].' s' : '—' }}</td>
                <td>{{ $e['review_status'] }}</td>
                <td>{{ $e['reviewer'] ?? '—' }} {{ $e['review_reason'] }} {{ $e['action_note'] }}</td>
                <td>snapshot {{ $e['snapshot'] }}, klip {{ $e['clip'] }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Tidak ada kejadian sesuai filter.</td></tr>
        @endforelse
    </table>

    <h2>Operasional</h2>
    <table class="grid">
        <tr><td>Analisis gagal</td><td>{{ $data['operational']['failed_runs'] }}</td></tr>
        <tr><td>Sumber tak terbaca</td><td>{{ $data['operational']['source_unreadable'] }}</td></tr>
        <tr><td>Worker stall</td><td>{{ $data['operational']['analysis_stalled'] }}</td></tr>
    </table>
</body>
</html>
