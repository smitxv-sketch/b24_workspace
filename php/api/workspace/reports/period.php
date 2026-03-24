<?php
/**
 * Периоды/ось времени для planning-представления.
 */

function wsPlanningBuildTimeline(string $scale): array {
    $scale = strtolower(trim($scale));
    $today = new \DateTimeImmutable('today');

    if ($scale === 'week') {
        $past = 6;
        $future = 18;
        $todayWeekStart = wsStartOfWeek($today);
        $from = $todayWeekStart->modify("-{$past} weeks");
        $to = $todayWeekStart->modify('+' . ($future) . ' weeks');
        $timeline = [];
        $cursor = $from;
        $todayIndex = 0;
        $idx = 0;
        while ($cursor <= $to) {
            $end = $cursor->modify('+6 days');
            if ($cursor->format('Y-m-d') === $todayWeekStart->format('Y-m-d')) {
                $todayIndex = $idx;
            }
            $timeline[] = [
                'index' => $idx,
                'scale' => 'week',
                'from' => $cursor->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
                'label' => $cursor->format('d.m') . ' — ' . $end->format('d.m'),
                'is_today_bucket' => $cursor <= $today && $today <= $end,
            ];
            $cursor = $cursor->modify('+1 week');
            $idx++;
        }
        return [
            'scale' => 'week',
            'timeline' => $timeline,
            'today_index' => $todayIndex,
            'range' => ['from' => $timeline[0]['from'], 'to' => $timeline[count($timeline) - 1]['to']],
            'anchor_quarter_index' => (int)floor((count($timeline) - 1) / 4),
        ];
    }

    if ($scale === 'month') {
        $past = 4;
        $future = 14;
        $todayMonth = new \DateTimeImmutable($today->format('Y-m-01'));
        $from = $todayMonth->modify("-{$past} months");
        $to = $todayMonth->modify('+' . ($future) . ' months');
        $timeline = [];
        $cursor = $from;
        $todayIndex = 0;
        $idx = 0;
        while ($cursor <= $to) {
            $end = $cursor->modify('last day of this month');
            if ($cursor->format('Y-m-d') === $todayMonth->format('Y-m-d')) {
                $todayIndex = $idx;
            }
            $timeline[] = [
                'index' => $idx,
                'scale' => 'month',
                'from' => $cursor->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
                'label' => wsMonthRu($cursor) . ' ' . $cursor->format('Y'),
                'is_today_bucket' => $cursor <= $today && $today <= $end,
            ];
            $cursor = $cursor->modify('first day of next month');
            $idx++;
        }
        return [
            'scale' => 'month',
            'timeline' => $timeline,
            'today_index' => $todayIndex,
            'range' => ['from' => $timeline[0]['from'], 'to' => $timeline[count($timeline) - 1]['to']],
            'anchor_quarter_index' => (int)floor((count($timeline) - 1) / 4),
        ];
    }

    // По умолчанию day.
    $past = 21;
    $future = 63;
    $from = $today->modify("-{$past} days");
    $to = $today->modify("+{$future} days");
    $timeline = [];
    $cursor = $from;
    $todayIndex = 0;
    $idx = 0;
    while ($cursor <= $to) {
        if ($cursor->format('Y-m-d') === $today->format('Y-m-d')) {
            $todayIndex = $idx;
        }
        $timeline[] = [
            'index' => $idx,
            'scale' => 'day',
            'from' => $cursor->format('Y-m-d'),
            'to' => $cursor->format('Y-m-d'),
            'label' => $cursor->format('d.m'),
            'is_today_bucket' => $cursor->format('Y-m-d') === $today->format('Y-m-d'),
        ];
        $cursor = $cursor->modify('+1 day');
        $idx++;
    }
    return [
        'scale' => 'day',
        'timeline' => $timeline,
        'today_index' => $todayIndex,
        'range' => ['from' => $timeline[0]['from'], 'to' => $timeline[count($timeline) - 1]['to']],
        'anchor_quarter_index' => (int)floor((count($timeline) - 1) / 4),
    ];
}

function wsStartOfWeek(\DateTimeImmutable $d): \DateTimeImmutable {
    $day = (int)$d->format('N'); // 1..7
    return $d->modify('-' . ($day - 1) . ' days');
}

function wsMonthRu(\DateTimeImmutable $d): string {
    $months = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
    return $months[(int)$d->format('n') - 1];
}

