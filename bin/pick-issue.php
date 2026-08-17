<?php

/**
 * bin/pick-issue.php — rank open issues and print the top candidates.
 *
 * ScanMePHP has no milestones, so unlike the workerman-bundle version there is
 * no release gate: every open issue is eligible. Issues are scored by type
 * labels, priority labels, title signals (leak/crash/security/performance),
 * age and comment count. The script never reads issue bodies or comment text.
 *
 * Usage:
 *   php bin/pick-issue.php                 # top 5
 *   php bin/pick-issue.php --top=10        # top 10
 *   php bin/pick-issue.php --json          # machine-readable
 *
 * Exit codes: 0 candidates printed, 1 error, 2 no open issues.
 */
declare(strict_types=1);

$opts = getopt('', ['top::', 'json']);
$top = isset($opts['top']) ? (int) $opts['top'] : 5;
$json = isset($opts['json']);

/** Run a command, return [exitCode, stdout]. */
function run(string $cmd): array
{
    $out = [];
    $exit = 0;
    exec($cmd . ' 2>&1', $out, $exit);
    return [$exit, implode("\n", $out)];
}

/** Paginate gh issue list (gh caps at 30/1000 per page). */
function fetchOpenIssues(): array
{
    $all = [];
    while (true) {
        [$exit, $out] = run('gh issue list --state open --limit 100 --json number,title,labels,createdAt,comments --paginate 2>/dev/null || gh issue list --state open --limit 1000 --json number,title,labels,createdAt,comments');
        if ($exit !== 0) {
            fwrite(STDERR, "gh issue list failed: {$out}\n");
            exit(1);
        }
        $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            break;
        }
        // gh with --paginate already merges; one call is enough.
        $all = $data;
        break;
    }
    return $all;
}

/** Score a single issue. Returns [score, breakdown]. */
function scoreIssue(array $issue): array
{
    $score = 0;
    $breakdown = [];
    $labels = array_map(static fn (array $l): string => strtolower($l['name'] ?? ''), $issue['labels'] ?? []);
    $title = strtolower($issue['title'] ?? '');

    // Type labels
    foreach (['bug' => 30, 'security' => 40, 'enhancement' => 20, 'good-first-issue' => 15, 'code-quality' => 15, 'documentation' => 10] as $label => $points) {
        if (in_array($label, $labels, true)) {
            $score += $points;
            $breakdown[] = "label:{$label} +{$points}";
        }
    }

    // Priority labels
    foreach (['critical' => 30, 'high' => 20, 'medium' => 10, 'minor' => 3] as $label => $points) {
        if (in_array($label, $labels, true)) {
            $score += $points;
            $breakdown[] = "priority:{$label} +{$points}";
            break;
        }
    }

    // Title signals
    foreach (['leak' => 25, 'crash' => 25, 'security' => 25, 'performance' => 15, 'fail' => 10, 'broken' => 10] as $sig => $points) {
        if (str_contains($title, $sig)) {
            $score += $points;
            $breakdown[] = "title:{$sig} +{$points}";
        }
    }

    // Age: +1 per 30 days old, capped at 10
    $created = strtotime($issue['createdAt'] ?? 'now');
    $days = max(0, (int) floor((time() - $created) / 86400));
    $agePoints = min(10, (int) floor($days / 30));
    if ($agePoints > 0) {
        $score += $agePoints;
        $breakdown[] = "age:{$days}d +{$agePoints}";
    }

    // Comments: +2 per comment, capped at 10
    $comments = (int) ($issue['comments'] ?? 0);
    $commentPoints = min(10, $comments * 2);
    if ($commentPoints > 0) {
        $score += $commentPoints;
        $breakdown[] = "comments:{$comments} +{$commentPoints}";
    }

    return [$score, $breakdown];
}

$issues = fetchOpenIssues();
if ($issues === []) {
    if ($json) {
        echo json_encode(['candidates' => [], 'note' => 'No open issues.'], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "No open issues to rank.\n";
    }
    exit(2);
}

$scored = [];
foreach ($issues as $issue) {
    [$score, $breakdown] = scoreIssue($issue);
    $scored[] = [
        'number' => (int) $issue['number'],
        'title' => $issue['title'] ?? '',
        'labels' => array_map(static fn (array $l): string => $l['name'] ?? '', $issue['labels'] ?? []),
        'score' => $score,
        'breakdown' => $breakdown,
    ];
}

usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['number'] <=> $b['number']);
$top = array_slice($scored, 0, $top);

if ($json) {
    echo json_encode(['candidates' => $top], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($top === []) {
    echo "No candidates after scoring.\n";
    exit(2);
}

echo 'Top ' . count($top) . " open issues (scored, highest first):\n\n";
foreach ($top as $i => $c) {
    $n = $i + 1;
    echo "{$n}. #{$c['number']} [score={$c['score']}] {$c['title']}\n";
    if ($c['labels'] !== []) {
        echo '   labels: ' . implode(', ', $c['labels']) . "\n";
    }
    echo '   ' . implode(' | ', $c['breakdown']) . "\n\n";
}
exit(0);
