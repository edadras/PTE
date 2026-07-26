{{--
    Rendered by ReportCardRenderer. Self-contained by design: the same markup is
    shown in the panel, mailed, and (once a headless-browser step exists) turned
    into the image the bot sends, where an external stylesheet would not resolve.

    Every colour comes from AcademyBrand — nothing in here may hard-code the
    platform's own palette, or a white-label academy would be handing its
    students a report card in someone else's colours (docs/03).
--}}
@php
    /** @var array<string, mixed> $card */
    /** @var array<string, string> $palette */
    $total = $card['total'] ?? [];
    $percentage = (float) ($total['percentage'] ?? 0.0);
    $dir = $rtl ? 'rtl' : 'ltr';
    $align = $rtl ? 'right' : 'left';
    $isExam = ($card['kind'] ?? 'practice') === 'exam';

    $bandColor = static function (float $value) use ($palette): string {
        return match (true) {
            $value >= 75.0 => $palette['success'],
            $value >= 55.0 => $palette['accent'],
            default => $palette['danger'],
        };
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('reports.report_card.title') }}</title>
    <style>
        :root {
            --primary: {{ $palette['primary'] }};
            --secondary: {{ $palette['secondary'] }};
            --accent: {{ $palette['accent'] }};
            --success: {{ $palette['success'] }};
            --danger: {{ $palette['danger'] }};
        }
        * { box-sizing: border-box; }
        body {
            font-family: {{ $brand->font_family ?? 'Tahoma, "DejaVu Sans", sans-serif' }};
            margin: 0; padding: 24px; background: #f4f5f7; color: #1f2933; font-size: 14px;
        }
        .card { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 24px rgba(15, 23, 42, .08); }
        .head { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; padding: 24px; display: flex; align-items: center; gap: 16px; }
        .head img { height: 44px; width: auto; border-radius: 8px; background: rgba(255,255,255,.15); }
        .head h1 { margin: 0; font-size: 18px; font-weight: 700; }
        .head .sub { opacity: .85; font-size: 12px; margin-top: 2px; }
        .body { padding: 24px; }
        .score { text-align: center; margin-bottom: 24px; }
        .score .value { font-size: 44px; font-weight: 800; line-height: 1; }
        .score .of { color: #6b7280; font-size: 13px; margin-top: 6px; }
        .bar { height: 10px; border-radius: 999px; background: #e5e7eb; overflow: hidden; margin: 14px auto 0; max-width: 420px; }
        .bar span { display: block; height: 100%; border-radius: 999px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #eef0f3; text-align: {{ $align }}; }
        th { font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        td.num { text-align: {{ $rtl ? 'left' : 'right' }}; white-space: nowrap; font-variant-numeric: tabular-nums; }
        h2 { font-size: 14px; margin: 24px 0 4px; color: #374151; }
        .pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .pill { padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .pill.good { background: color-mix(in srgb, var(--success) 14%, #fff); color: var(--success); }
        .pill.bad { background: color-mix(in srgb, var(--danger) 14%, #fff); color: var(--danger); }
        .note { margin-top: 16px; padding: 10px 14px; border-radius: 10px; background: #fff7ed; color: #9a3412; font-size: 12px; }
        footer { padding: 16px 24px; background: #fafbfc; color: #9ca3af; font-size: 11px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        @media (max-width: 520px) { body { padding: 8px; } .head { flex-direction: column; text-align: center; } }
    </style>
</head>
<body>
<div class="card">
    <div class="head">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $academyName }}">
        @endif
        <div>
            <h1>{{ $academyName }}</h1>
            <div class="sub">
                {{ $isExam ? __('reports.report_card.exam_result') : __('reports.report_card.practice_result') }}
                @if ($isExam && filled($card['exam']['title'] ?? null))
                    · {{ $card['exam']['title'] }}
                @endif
            </div>
        </div>
    </div>

    <div class="body">
        <div class="score">
            <div class="value" style="color: {{ $bandColor($percentage) }}">{{ number_format($percentage, 1) }}%</div>
            <div class="of">
                {{ __('reports.report_card.score_of', [
                    'score' => number_format((float) ($total['score'] ?? 0), 2),
                    'max' => number_format((float) ($total['max'] ?? 0), 2),
                ]) }}
                @if ($isExam && ($total['passing_score'] ?? null) !== null)
                    · {{ __('reports.report_card.passing_score', ['score' => $total['passing_score']]) }}
                @endif
            </div>
            <div class="bar">
                <span style="width: {{ max(0, min(100, $percentage)) }}%; background: {{ $bandColor($percentage) }}"></span>
            </div>
        </div>

        <div><strong>{{ $card['student']['name'] ?? '' }}</strong></div>

        @if (! empty($card['sections']))
            <h2>{{ __('reports.report_card.sections') }}</h2>
            <table>
                <thead>
                <tr>
                    <th>{{ __('reports.report_card.section') }}</th>
                    <th class="num">{{ __('reports.report_card.score') }}</th>
                    <th class="num">{{ __('reports.report_card.percentage') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($card['sections'] as $section)
                    <tr>
                        <td>{{ $section['title'] }}</td>
                        <td class="num">{{ $section['score'] }} / {{ $section['max'] }}</td>
                        <td class="num" style="color: {{ $bandColor((float) $section['percentage']) }}">{{ $section['percentage'] }}%</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if (! empty($card['by_type']))
            <h2>{{ __('reports.report_card.by_type') }}</h2>
            <table>
                <thead>
                <tr>
                    <th>{{ __('reports.report_card.question_type') }}</th>
                    <th class="num">{{ __('reports.report_card.attempts') }}</th>
                    <th class="num">{{ __('reports.report_card.percentage') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($card['by_type'] as $row)
                    <tr>
                        <td>{{ $row['label'] ?? $row['type'] }}</td>
                        <td class="num">{{ $row['count'] ?? 0 }}</td>
                        <td class="num" style="color: {{ $bandColor((float) $row['percentage']) }}">{{ $row['percentage'] }}%</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if (! empty($card['strengths']))
            <h2>{{ __('reports.report_card.strengths') }}</h2>
            <div class="pills">
                @foreach ($card['strengths'] as $row)
                    <span class="pill good">{{ $row['label'] ?? $row['type'] }} · {{ $row['percentage'] }}%</span>
                @endforeach
            </div>
        @endif

        @if (! empty($card['weaknesses']))
            <h2>{{ __('reports.report_card.weaknesses') }}</h2>
            <div class="pills">
                @foreach ($card['weaknesses'] as $row)
                    <span class="pill bad">{{ $row['label'] ?? $row['type'] }} · {{ $row['percentage'] }}%</span>
                @endforeach
            </div>
        @endif

        @if ((int) ($card['pending'] ?? 0) > 0)
            <div class="note">{{ __('reports.report_card.pending', ['count' => $card['pending']]) }}</div>
        @endif
    </div>

    <footer>
        <span>{{ $brand->footer_text ?? $academyName }}</span>
        <span>{{ __('reports.report_card.generated_at', ['at' => $card['generated_at'] ?? '']) }}</span>
    </footer>
</div>
</body>
</html>
