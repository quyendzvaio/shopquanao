<?php

declare(strict_types=1);

/**
 * Deterministic agent eval: UC-A outfits, UC-C free-shipping, guardrails and
 * unrelated suppression. In-process ChatbotService, internal tools, no LLM.
 * Usage: php scripts/run_agent_eval.php [--cases=eval/cases.jsonl] [--output=reports/eval/agent_eval.json]
 */
putenv('CHATBOT_TOOL_TRANSPORT=internal');
putenv('APP_ENV=test');
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/eval/AgentEvalCaseRunner.php';

/** @var PDO $pdo */
$options = getopt('', ['cases::', 'output::']);
$casesFile = (string)($options['cases'] ?? dirname(__DIR__) . '/eval/cases.jsonl');
$output = (string)($options['output'] ?? dirname(__DIR__) . '/reports/eval/agent_eval.json');

$runner = new AgentEvalCaseRunner($pdo, HarnessConfig::fromEnvironment()->freeShipping);
$report = ['generated_at' => date('c'), 'passed' => 0, 'failed' => 0, 'by_group' => [], 'cases' => []];
foreach (file($casesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $outcome = $runner->run($case);
    $passed = $outcome['failures'] === [];
    $report[$passed ? 'passed' : 'failed']++;
    $group = $outcome['group'];
    $report['by_group'][$group] = ($report['by_group'][$group] ?? 0) + ($passed ? 1 : 0);
    $report['cases'][] = $outcome;
    fwrite(STDOUT, sprintf("%s %s %s\n", $passed ? 'PASS' : 'FAIL', $outcome['id'], implode('; ', $outcome['failures'])));
}
if (!is_dir(dirname($output))) mkdir(dirname($output), 0775, true);
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$total = $report['passed'] + $report['failed'];
fwrite(STDOUT, sprintf("%d/%d passed -> %s\n", $report['passed'], $total, $output));
exit($report['failed'] === 0 && $total > 0 ? 0 : 1);
