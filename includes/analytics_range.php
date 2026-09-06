<?php
/**
 * One definition of an analytics period, shared by the dashboard charts and the
 * reports page so the two can never disagree about what "last 6 months" means.
 *
 * Every chart on the dashboard used to be hard-coded: "last 7 days", "last 6
 * months", "last 30 days". The five period dropdowns above them had no name, no
 * id and no listener -- they were markup, and changing one did nothing at all.
 * The API had no period parameter to send them to either, so both halves had to
 * be built, not fixed.
 *
 * The bucket granularity is derived from the span, never chosen separately: a
 * year of daily bars is unreadable and a week of monthly ones is a single
 * column. Anything up to 90 days is bucketed by day, longer by month.
 */

/** The periods offered, in the order they appear in the picker. */
function analyticsRanges(): array
{
    return [
        '7d'  => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '6m'  => 'Last 6 months',
        '12m' => 'Last 12 months',
        'ytd' => 'This year',
    ];
}

/**
 * Resolve a period key into everything a caller needs to query and label it.
 *
 * Returns start/end as dates, the bucket granularity, and the full list of
 * buckets in order — including the empty ones. Deriving the buckets here rather
 * than from the query results is what makes a month with no payments show as a
 * zero instead of vanishing and silently shifting every later bar left.
 */
function analyticsRange(?string $key): array
{
    $ranges = analyticsRanges();
    $key    = is_string($key) ? strtolower(trim($key)) : '';
    if (!isset($ranges[$key])) $key = '7d';

    $today = new DateTimeImmutable('today');

    switch ($key) {
        case '30d':
            $bucket = 'day';   $start = $today->modify('-29 days'); break;
        case '90d':
            $bucket = 'day';   $start = $today->modify('-89 days'); break;
        case '6m':
            $bucket = 'month'; $start = $today->modify('first day of this month')->modify('-5 months'); break;
        case '12m':
            $bucket = 'month'; $start = $today->modify('first day of this month')->modify('-11 months'); break;
        case 'ytd':
            $bucket = 'month'; $start = $today->modify('first day of January this year'); break;
        case '7d':
        default:
            $key = '7d';
            $bucket = 'day';   $start = $today->modify('-6 days'); break;
    }

    // Buckets in order, each with the key the SQL will group by and the label
    // the chart axis shows.
    $buckets = [];
    if ($bucket === 'day') {
        for ($d = $start; $d <= $today; $d = $d->modify('+1 day')) {
            $buckets[] = ['key' => $d->format('Y-m-d'), 'label' => $d->format('D d')];
        }
    } else {
        $cursor = $start;
        $last   = $today->modify('first day of this month');
        while ($cursor <= $last) {
            $buckets[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')];
            $cursor = $cursor->modify('+1 month');
        }
    }

    return [
        'key'     => $key,
        'label'   => $ranges[$key],
        'bucket'  => $bucket,
        'start'   => $start->format('Y-m-d'),
        // Inclusive of today: callers compare with `< end_exclusive` so a
        // payment made this afternoon is not dropped by a DATE() comparison.
        'end'           => $today->format('Y-m-d'),
        'end_exclusive' => $today->modify('+1 day')->format('Y-m-d'),
        'buckets'       => $buckets,
    ];
}

/** The MySQL expression that groups a date column into this range's buckets. */
function analyticsBucketExpr(array $spec, string $column): string
{
    return $spec['bucket'] === 'day'
        ? "DATE($column)"
        : "DATE_FORMAT($column, '%Y-%m')";
}

/**
 * Map grouped rows onto the range's buckets, filling the gaps with zero.
 *
 * @param array $rows  Rows of [bucket_key => value], as returned by a
 *                     `SELECT <bucketExpr> AS k, SUM(...) AS v ... GROUP BY k`.
 */
function analyticsSeries(array $spec, array $rows, string $keyCol = 'k', string $valCol = 'v', bool $asInt = false): array
{
    $byKey = [];
    foreach ($rows as $r) {
        // MySQL returns DATE() as 'Y-m-d' and DATE_FORMAT as 'Y-m'; both match
        // the bucket keys built above without any further massaging.
        $byKey[(string)$r[$keyCol]] = $asInt ? (int)$r[$valCol] : (float)$r[$valCol];
    }

    $labels = [];
    $values = [];
    foreach ($spec['buckets'] as $b) {
        $labels[] = $b['label'];
        $values[] = $byKey[$b['key']] ?? ($asInt ? 0 : 0.0);
    }

    return ['labels' => $labels, 'data' => $values];
}

/**
 * Run one grouped query over a date column and return a filled series.
 *
 * Replaces the per-day query loops the dashboard used to run: seven queries for
 * a week was tolerable, but the same shape over twelve months would have been
 * twelve round trips per chart, and there are five charts.
 */
function analyticsQuerySeries(
    PDO $pdo, array $spec, string $table, string $dateColumn,
    string $valueExpr, int $tenantId, string $extraWhere = '', array $extraParams = [], bool $asInt = false
): array {
    $expr = analyticsBucketExpr($spec, $dateColumn);
    $sql  = "SELECT $expr AS k, $valueExpr AS v
             FROM $table
             WHERE tenant_id = ?
               AND $dateColumn >= ? AND $dateColumn < ?
               $extraWhere
             GROUP BY k";

    try {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$tenantId, $spec['start'], $spec['end_exclusive']], $extraParams));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[analytics] ' . $table . '.' . $dateColumn . ': ' . $e->getMessage());
        $rows = [];
    }

    return analyticsSeries($spec, $rows, 'k', 'v', $asInt);
}
