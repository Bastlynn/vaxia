<?php

/**
 * @file
 * Builds coverage reports from the hits tests/coverage/prepend.php recorded.
 *
 *   php tests/coverage/report.php <raw dir> <output dir>
 *
 * Every VAXIA PHP file is analysed for executable lines (php-code-coverage's
 * parser), so files no test reached count as 0%. Features exports (rules,
 * views, fields, strongarm...) are data, not logic, and are left out.
 * Writes html/, clover.xml and summary.txt.
 */

require '/opt/tools/vendor/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

$raw_dir = $argv[1] ?? '/var/www/tests/.build/coverage/raw';
$out = $argv[2] ?? '/var/www/tests/.build/coverage/report';
$roots = array('/var/www/html/sites/all/modules/vaxia', '/var/www/html/sites/all/modules/patched');

// Data files from features exports: arrays, not behaviour.
$is_export = function ($file) {
  return (bool) preg_match('/\.(features(\.[a-z_]+)?|views_default|rules_defaults|strongarm|field_group|default_[a-z_]+|pages_default|layouts)\.inc$/', $file);
};

// 1. Every VAXIA PHP file.
$files = array();
foreach ($roots as $root) {
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS));
  foreach ($it as $f) {
    $path = $f->getPathname();
    if (preg_match('/\.(module|inc|install|php)$/', $path) && !$is_export($path)) {
      $files[] = $path;
    }
  }
}
sort($files);

// 2. Hits, merged across every process.
$hits = array();
// Raw files may sit in per-box subdirectories (CircleCI).
$raw_files = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raw_dir, FilesystemIterator::SKIP_DOTS)) as $f) {
  if (substr($f->getFilename(), -4) === '.ser') {
    $raw_files[] = $f->getPathname();
  }
}
foreach ($raw_files as $raw) {
  foreach (unserialize(file_get_contents($raw)) as $file => $lines) {
    $hits[$file] = isset($hits[$file]) ? $hits[$file] + $lines : $lines;
  }
}

// 3. Executable lines per file: not executed unless hit.
$analyser = new ParsingFileAnalyser(TRUE, FALSE);
$line_data = array();
foreach ($files as $file) {
  try {
    $executable = $analyser->executableLinesIn($file);
  }
  catch (Throwable $e) {
    fwrite(STDERR, "Skipping $file: " . $e->getMessage() . "\n");
    continue;
  }
  // Xdebug reports a multi-line statement (an array literal, a long call) on
  // one of its lines only; the analyser groups the lines of each statement
  // into a branch. A branch with any line run counts as covered.
  $hit_branches = array();
  foreach ($executable as $line => $branch) {
    if (isset($hits[$file][$line])) {
      $hit_branches[$branch] = TRUE;
    }
  }
  $lines = array();
  foreach ($executable as $line => $branch) {
    $lines[$line] = isset($hit_branches[$branch]) ? 1 : -1;
  }
  if ($lines) {
    $line_data[$file] = $lines;
  }
}

$filter = new Filter();
$filter->includeFiles(array_keys($line_data));
// The data is already collected: a driver that never runs.
$driver = new class() extends Driver {
  public function nameAndVersion(): string {
    return 'VAXIA prepend collector';
  }
  public function start(): void {
  }
  public function stop(): RawCodeCoverageData {
    return RawCodeCoverageData::fromXdebugWithoutPathCoverage(array());
  }
};
$coverage = new CodeCoverage($driver, $filter);
$coverage->excludeUncoveredFiles();
$coverage->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($line_data), 'vaxia-suite');

@mkdir($out, 0777, TRUE);
(new HtmlReport())->process($coverage, $out . '/html');
(new Clover())->process($coverage, $out . '/clover.xml', 'VAXIA');
$text = (new Text(50, 90, TRUE, FALSE))->process($coverage, FALSE);
file_put_contents($out . '/summary.txt', $text);

// Per module: covered / executable lines.
$modules = array();
foreach ($line_data as $file => $lines) {
  $rel = str_replace($roots, array('', 'patched'), $file);
  $parts = explode('/', trim($rel, '/'));
  $module = $parts[0] === 'patched' ? 'patched/' . $parts[1] : $parts[0] . '/' . $parts[1];
  $modules[$module]['lines'] = ($modules[$module]['lines'] ?? 0) + count($lines);
  $modules[$module]['hit'] = ($modules[$module]['hit'] ?? 0) + count(array_filter($lines, function ($v) {
    return $v === 1;
  }));
}
ksort($modules);
$row = function ($name, $lines, $hit) {
  $pct = number_format(100 * $hit / max(1, $lines), 1) . '%';
  return str_pad($name, 40) . ' ' . str_pad((string) $lines, 8, ' ', STR_PAD_LEFT) . ' '
    . str_pad((string) $hit, 8, ' ', STR_PAD_LEFT) . ' ' . str_pad($pct, 7, ' ', STR_PAD_LEFT) . "\n";
};
$table = str_pad('Module', 40) . ' ' . str_pad('Lines', 8, ' ', STR_PAD_LEFT) . ' '
  . str_pad('Covered', 8, ' ', STR_PAD_LEFT) . ' ' . str_pad('%', 7, ' ', STR_PAD_LEFT) . "\n";
$total_lines = $total_hit = 0;
foreach ($modules as $module => $m) {
  $table .= $row($module, $m['lines'], $m['hit']);
  $total_lines += $m['lines'];
  $total_hit += $m['hit'];
}
$table .= $row('TOTAL', $total_lines, $total_hit);
file_put_contents($out . '/modules.txt', $table);
echo $table;
