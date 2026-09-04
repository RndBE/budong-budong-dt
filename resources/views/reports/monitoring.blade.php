<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #17222e; margin: 0; }
        .header { border-bottom: 2px solid #1268c9; padding-bottom: 10px; margin-bottom: 14px; }
        .header h1 { font-size: 16px; margin: 0 0 3px; color: #0a1c2c; }
        .header p { margin: 1px 0; color: #55677a; font-size: 9.5px; }
        h2 { font-size: 11.5px; margin: 16px 0 6px; color: #0a1c2c; border-left: 3px solid #1268c9; padding-left: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d7e0ea; padding: 4px 6px; text-align: left; }
        th { background: #eef4fb; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #37485c; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .kpi { width: 100%; margin-bottom: 4px; }
        .kpi td { border: 1px solid #d7e0ea; width: 25%; padding: 7px 8px; }
        .kpi .label { font-size: 8.5px; color: #55677a; text-transform: uppercase; }
        .kpi .value { font-size: 14px; font-weight: bold; color: #0a1c2c; }
        .pill { padding: 1px 5px; border-radius: 8px; font-size: 8.5px; font-weight: bold; }
        .aman { background: #e4f8ef; color: #197a53; }
        .waspada { background: #fdf3dc; color: #8a6208; }
        .siaga { background: #fdeadb; color: #96470f; }
        .bahaya { background: #fdE4e4; color: #9b1c1c; }
        .footer { margin-top: 18px; border-top: 1px solid #d7e0ea; padding-top: 6px; font-size: 8.5px; color: #7a8a9c; }
    </style>
</head>
<body>

<div class="header">
    <h1>{{ $title }}</h1>
    <p><strong>{{ $dam->name }}</strong> — {{ $dam->authority }} · {{ $dam->regency }}, {{ $dam->province }}</p>
    <p>Periode {{ $from->translatedFormat('d F Y') }} – {{ $to->translatedFormat('d F Y') }}
        · dicetak {{ now($dam->timezone)->translatedFormat('d F Y H:i') }}</p>
</div>

@if (in_array('ringkasan', $sections, true))
    <h2>Ringkasan Kondisi</h2>
    <table class="kpi">
        <tr>
            <td>
                <div class="label">Skor Kesehatan Struktur</div>
                <div class="value">{{ $summary['health_score'] }}%</div>
            </td>
            <td>
                <div class="label">Stasiun Terpantau</div>
                <div class="value">{{ $summary['stations_total'] }}</div>
            </td>
            <td>
                <div class="label">Peringatan Periode</div>
                <div class="value">{{ $summary['alerts_total'] }}</div>
            </td>
            <td>
                <div class="label">Elevasi Puncak</div>
                <div class="value">{{ number_format((float) $dam->crest_elevation, 2, ',', '.') }}</div>
            </td>
        </tr>
    </table>
@endif

@if (in_array('parameter', $sections, true))
    <h2>Parameter Utama</h2>
    <table>
        <thead>
            <tr>
                <th>Parameter</th>
                <th>Nilai</th>
                <th>Satuan</th>
                <th>Status</th>
                <th>Waktu Baca</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dashboard['primary'] as $tile)
                <tr>
                    <td>{{ $tile['label'] }}</td>
                    <td class="num">{{ $tile['formatted'] }}</td>
                    <td>{{ $tile['unit'] }}</td>
                    <td><span class="pill {{ config("dam.statuses.{$tile['status']}.bucket") }}">{{ config("dam.statuses.{$tile['status']}.label") }}</span></td>
                    <td>{{ $tile['recorded_label'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Riwayat Data Terakhir</h2>
    <table>
        <thead>
            <tr>
                <th>Parameter</th>
                <th>Nilai</th>
                <th>Status</th>
                <th>Waktu</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dashboard['recent'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['formatted'] }} {{ $row['unit'] }}</td>
                    <td><span class="pill {{ config("dam.statuses.{$row['status']}.bucket") }}">{{ $row['status_label'] }}</span></td>
                    <td>{{ $row['recorded_label'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (in_array('instrumentasi', $sections, true))
    <h2>Daftar Instrumentasi</h2>
    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Stasiun</th>
                <th>Tipe</th>
                <th>Zona</th>
                <th>Parameter Utama</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($markers as $marker)
                <tr>
                    <td>{{ strtoupper($marker['code']) }}</td>
                    <td>{{ $marker['name'] }}</td>
                    <td>{{ $marker['type_label'] }}</td>
                    <td>{{ $marker['zone'] ?? '—' }}</td>
                    <td class="num">{{ $marker['caption'] }}</td>
                    <td><span class="pill {{ config("dam.statuses.{$marker['status']}.bucket") }}">{{ $marker['status_label'] }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (in_array('peringatan', $sections, true))
    <h2>Peringatan Periode</h2>
    @if ($alerts->isEmpty())
        <p>Tidak ada peringatan pada periode ini.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Level</th>
                    <th>Kejadian</th>
                    <th>Stasiun</th>
                    <th>Nilai</th>
                    <th>Ambang</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($alerts as $alert)
                    <tr>
                        <td>{{ $alert->triggered_at->setTimezone($dam->timezone)->translatedFormat('d/m/Y H:i') }}</td>
                        <td><span class="pill {{ config("dam.statuses.{$alert->level}.bucket") }}">{{ ucfirst($alert->level) }}</span></td>
                        <td>{{ $alert->title }}</td>
                        <td>{{ $alert->station?->name ?? '—' }}</td>
                        <td class="num">{{ $alert->value !== null ? number_format((float) $alert->value, 2, ',', '.') : '—' }}</td>
                        <td class="num">{{ $alert->threshold !== null ? number_format((float) $alert->threshold, 2, ',', '.') : '—' }}</td>
                        <td>{{ $alert->resolved_at ? 'Selesai' : 'Aktif' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

<div class="footer">
    Dokumen dibangkitkan otomatis oleh Digital Twin &amp; Dam Monitoring System.
    Data instrumentasi bersumber dari stasiun telemetri {{ $dam->name }}.
</div>

</body>
</html>
