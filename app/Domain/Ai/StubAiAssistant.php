<?php

namespace App\Domain\Ai;

/**
 * Deterministic replies so the UI and Cloud Run deploy work before any API key exists.
 */
class StubAiAssistant implements AiAssistant
{
    public function provider(): string
    {
        return 'stub';
    }

    public function summarizeEvent(array $event, string $locale = 'en'): string
    {
        $locale = AiPrompts::normalizeLocale($locale);
        $type = (string) ($event['type_label'] ?? $event['type'] ?? 'event');
        $source = (string) ($event['source'] ?? $event['device_name'] ?? 'camera');
        $when = (string) ($event['occurred_at'] ?? $event['created_at'] ?? 'unknown time');

        if ($locale === 'id') {
            return trim(<<<TXT
[Mode demo — Gemini belum terhubung]

**Ringkasan:** {$type} terdeteksi di {$source} pada {$when}.

**Tindak lanjut:**
- [ ] Tinjau bukti di panel review
- [ ] Konfirmasi atau abaikan kejadian
- [ ] Catat tindakan korektif bila perlu

Setel `AI_PROVIDER=gemini` dan `GEMINI_API_KEY` untuk ringkasan live.
TXT);
        }

        return trim(<<<TXT
[Demo mode — Gemini not connected]

**Summary:** {$type} detected at {$source} on {$when}.

**Next steps:**
- [ ] Review evidence in the review panel
- [ ] Confirm or dismiss the event
- [ ] Log corrective action if needed

Set `AI_PROVIDER=gemini` and `GEMINI_API_KEY` for live summaries.
TXT);
    }

    public function chatSop(string $message, array $history = [], ?string $sopContext = null, string $locale = 'en'): string
    {
        $locale = AiPrompts::normalizeLocale($locale);
        $message = AiSafety::sanitizeUserText($message);

        if (AiSafety::looksLikeJailbreak($message)) {
            return AiSafety::refusalMessage('chat');
        }

        if ($this->asksAboutWorkforce($message)) {
            return $this->stubWorkforceReply($sopContext, $locale, $message);
        }

        if ($this->asksAboutReports($message)) {
            return $this->stubPeriodReportsReply($sopContext, $locale, $message);
        }

        if ($locale === 'id') {
            $hint = $sopContext
                ? 'Konteks helper diterima (mode demo belum menafsirkan penuh).'
                : 'Belum ada snapshot konteks yang dilampirkan.';

            return trim(<<<TXT
[Mode demo — Gemini belum terhubung]

Pertanyaan Anda: **"{$message}"**

{$hint}

**Contoh jawaban helper:**
1. Di zona kerja dapur, penutup rambut dan celemek biasanya wajib.
2. Sarung tangan tergantung tugas (persiapan makanan vs mencuci).
3. Area terlarang tidak boleh dimasuki tanpa otorisasi.

**Checklist cepat:**
- [ ] Cek aturan zona di Aturan SOP
- [ ] Pastikan kamera area kritis online
- [ ] Tinjau anomali terbuka di antrean

Setel `GEMINI_API_KEY` + `AI_PROVIDER=gemini` untuk jawaban berbasis aturan Anda.
TXT);
        }

        $hint = $sopContext
            ? 'Helper context was received (demo mode does not fully interpret it).'
            : 'No helper context snapshot was attached.';

        return trim(<<<TXT
[Demo mode — Gemini not connected]

Your question: **"{$message}"**

{$hint}

**Sample helper answer:**
1. In kitchen work zones, hair covers and aprons are usually required.
2. Gloves depend on the task (food prep vs washing).
3. Restricted zones must not be entered without authorization.

**Quick checklist:**
- [ ] Check zone rules under SOP Rules
- [ ] Confirm critical cameras are online
- [ ] Review open anomalies in the queue

Set `GEMINI_API_KEY` + `AI_PROVIDER=gemini` for answers grounded in your rules.
TXT);
    }

    private function asksAboutReports(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return (bool) preg_match(
            '/\b(report|laporan|reports)\b|ada\s+report|what\s+reports?/u',
            $normalized,
        );
    }

    private function asksAboutWorkforce(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return (bool) preg_match(
            '/karyawan|pegawai|anggota|staff|workforce|team\s+member|berapa\s+orang|people\s+detected|jumlah\s+orang|berapa\s+karyawan|how\s+many\s+(people|staff|members|employees)/u',
            $normalized,
        );
    }

    /**
     * @return 'today'|'yesterday'|'this_month'
     */
    private function detectPeriod(string $message): string
    {
        $normalized = mb_strtolower($message);

        if (preg_match('/kemarin|yesterday/u', $normalized)) {
            return 'yesterday';
        }
        if (preg_match('/bulan\s*ini|this\s*month|bulan\s+berjalan/u', $normalized)) {
            return 'this_month';
        }

        return 'today';
    }

    private function stubPeriodReportsReply(?string $contextJson, string $locale, string $message): string
    {
        $period = $this->detectPeriod($message);
        $context = json_decode((string) $contextJson, true);
        $byPeriod = is_array($context['reports_by_period'] ?? null) ? $context['reports_by_period'] : [];
        $bucket = is_array($byPeriod[$period] ?? null) ? $byPeriod[$period] : [];
        $reports = is_array($bucket['items'] ?? null) ? $bucket['items'] : ($period === 'today' && is_array($context['reports_today'] ?? null) ? $context['reports_today'] : []);
        $count = (int) ($bucket['count'] ?? count($reports));

        $opsByPeriod = is_array($context['ops_by_period'] ?? null) ? $context['ops_by_period'] : [];
        $ops = is_array($opsByPeriod[$period] ?? null)
            ? $opsByPeriod[$period]
            : (is_array($context['ops_snapshot_today'] ?? null) ? $context['ops_snapshot_today'] : []);
        $open = (int) ($ops['open_or_acked_events'] ?? 0);
        $events = (int) ($ops['events_created'] ?? $ops['events_created_today'] ?? 0);
        $people = is_array($context['people_detected'][$period] ?? null)
            ? (int) ($context['people_detected'][$period]['people_tracks'] ?? 0)
            : 0;
        $team = (int) ($context['team']['active_member_count'] ?? 0);

        $periodLabel = match ($period) {
            'yesterday' => $locale === 'id' ? 'kemarin' : 'yesterday',
            'this_month' => $locale === 'id' ? 'bulan ini' : 'this month',
            default => $locale === 'id' ? 'hari ini' : 'today',
        };

        $lines = [];
        foreach (array_slice($reports, 0, 8) as $report) {
            if (! is_array($report)) {
                continue;
            }
            $format = strtoupper((string) ($report['format'] ?? '?'));
            $status = (string) ($report['status'] ?? '?');
            $when = (string) ($report['created_at'] ?? '');
            $who = (string) ($report['requester'] ?? '—');
            $lines[] = "- [ ] **{$format}** · {$status} · {$when} · {$who}";
        }

        $empty = $locale === 'id'
            ? "- (Belum ada laporan CSV/PDF untuk {$periodLabel})"
            : "- (No CSV/PDF reports for {$periodLabel} yet)";
        $list = $lines === [] ? $empty : implode("\n", $lines);

        if ($locale === 'id') {
            return trim(<<<TXT
[Mode demo — laporan fleksibel]

**Periode:** {$periodLabel}
**Laporan ekspor:** {$count} item

{$list}

**Snapshot operasional ({$periodLabel}):**
- Anomali dibuat: {$events}
- Orang terdeteksi (track CCTV): {$people}
- Tim DapurSense aktif: {$team}
- Masih OPEN / ACKNOWLEDGED: {$open}

**Bisa dilakukan sekarang:**
- [ ] Buka **Laporan** (`/reports`) untuk unduh CSV/PDF
- [ ] Tab **Laporan** di Asisten untuk narasi AI
- [ ] Tanya juga: kemarin / bulan ini / jumlah karyawan

Aktifkan Gemini untuk jawaban lebih naratif.
TXT);
        }

        return trim(<<<TXT
[Demo mode — flexible reports]

**Period:** {$periodLabel}
**Exported reports:** {$count} item(s)

{$list}

**Ops snapshot ({$periodLabel}):**
- Events created: {$events}
- People detected (CCTV tracks): {$people}
- Active DapurSense team members: {$team}
- Still OPEN / ACKNOWLEDGED: {$open}

**You can do now:**
- [ ] Open **Reports** (`/reports`) to download CSV/PDF
- [ ] Use Assistant **Report** tab for an AI narrative
- [ ] Also ask: yesterday / this month / staff count

Enable Gemini for a richer narrative.
TXT);
    }

    private function stubWorkforceReply(?string $contextJson, string $locale, string $message): string
    {
        $period = $this->detectPeriod($message);
        $context = json_decode((string) $contextJson, true);
        $team = is_array($context['team'] ?? null) ? $context['team'] : [];
        $members = is_array($team['members'] ?? null) ? $team['members'] : [];
        $memberCount = (int) ($team['active_member_count'] ?? count($members));
        $people = is_array($context['people_detected'][$period] ?? null)
            ? $context['people_detected'][$period]
            : [];
        $tracks = (int) ($people['people_tracks'] ?? 0);
        $runs = (int) ($people['succeeded_runs'] ?? 0);

        $periodLabel = match ($period) {
            'yesterday' => $locale === 'id' ? 'kemarin' : 'yesterday',
            'this_month' => $locale === 'id' ? 'bulan ini' : 'this month',
            default => $locale === 'id' ? 'hari ini' : 'today',
        };

        $memberLines = [];
        foreach (array_slice($members, 0, 12) as $member) {
            if (! is_array($member)) {
                continue;
            }
            $name = (string) ($member['name'] ?? '—');
            $role = (string) ($member['role'] ?? '—');
            $memberLines[] = "- **{$name}** · {$role}";
        }
        $memberList = $memberLines === []
            ? ($locale === 'id' ? '- (Belum ada anggota aktif)' : '- (No active members)')
            : implode("\n", $memberLines);

        if ($locale === 'id') {
            return trim(<<<TXT
[Mode demo — tim & kehadiran]

**Tim DapurSense (akun organisasi):** {$memberCount} anggota aktif

{$memberList}

**Orang terdeteksi CCTV ({$periodLabel}):**
- Person track: {$tracks}
- Analisis sukses: {$runs}

**Catatan:** Jumlah anggota = user yang terdaftar di DapurSense, **bukan** payroll HR. Person track = deteksi orang di video teranalisis.

- [ ] Kelola anggota di `/members`
- [ ] Tanya laporan **hari ini / kemarin / bulan ini**
TXT);
        }

        return trim(<<<TXT
[Demo mode — team & presence]

**DapurSense team (org accounts):** {$memberCount} active member(s)

{$memberList}

**People detected via CCTV ({$periodLabel}):**
- Person tracks: {$tracks}
- Succeeded analyses: {$runs}

**Note:** Team count = registered DapurSense users, **not** HR payroll. Person tracks = people seen in analysed video.

- [ ] Manage members at `/members`
- [ ] Ask for reports **today / yesterday / this month**
TXT);
    }

    public function dailyReport(array $stats, string $focus = 'operations', string $locale = 'en'): string
    {
        $locale = AiPrompts::normalizeLocale($locale);
        $open = (int) ($stats['open_events'] ?? 0);
        $people = (int) ($stats['people'] ?? 0);
        $restricted = (int) ($stats['restricted'] ?? 0);
        $videos = (int) ($stats['analysed_videos'] ?? 0);
        $focus = in_array($focus, ['operations', 'safety', 'throughput'], true) ? $focus : 'operations';

        if ($locale === 'id') {
            return trim(<<<TXT
[Mode demo — template laporan terkunci]

**Periode:** 7 hari terakhir · Fokus: **{$focus}**

**Sorotan KPI:**
- Video teranalisis: {$videos}
- Orang terdeteksi: {$people}
- Anomali terbuka: {$open}
- Kejadian zona terlarang: {$restricted}

**Risiko / item terbuka:**
- Selesaikan review terbuka sebelum jam sibuk berikutnya
- Pastikan kamera kritis tetap online

**Rekomendasi:**
- [ ] Bersihkan kejadian OPEN / ACKNOWLEDGED di antrean anomali
- [ ] Jalankan ulang analisis setelah edit zona
- [ ] Aktifkan Gemini untuk laporan naratif dari statistik live

Surface laporan ini tidak menerima instruksi bebas.
TXT);
        }

        return trim(<<<TXT
[Demo mode — locked report template]

**Period:** last 7 days · Focus preset: **{$focus}**

**KPI highlights:**
- Analysed videos: {$videos}
- People detected: {$people}
- Open anomalies: {$open}
- Restricted-zone events: {$restricted}

**Risks / open items:**
- Resolve open reviews before the next service peak
- Confirm critical cameras remain online

**Recommendations:**
- [ ] Clear OPEN / ACKNOWLEDGED events in the anomaly queue
- [ ] Re-run analysis after zone edits
- [ ] Enable Gemini for a narrative report from live statistics

This report surface does not accept free-form instructions.
TXT);
    }
}
