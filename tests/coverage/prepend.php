<?php

/**
 * @file
 * Line coverage collector for the black-box suite.
 *
 * Loaded into every PHP process of the web container (auto_prepend_file):
 * Apache requests, the PHPUnit process (Site arrange code) and drush. It does
 * nothing unless tests/.build/coverage/ON exists (tests/run-coverage.sh sets
 * it), so normal runs pay only for this file_exists().
 *
 * Uses Xdebug, which is only active when the container runs with
 * XDEBUG_MODE=coverage. Hits for VAXIA code (custom modules and
 * patched_modules) are merged into one file per process id under
 * tests/.build/coverage/raw; tests/coverage/report.php turns them into reports.
 */

if (function_exists('xdebug_start_code_coverage') && is_file('/var/www/tests/.build/coverage/ON')
  && strpos((string) ini_get('xdebug.mode') . ',' . getenv('XDEBUG_MODE'), 'coverage') !== FALSE) {
  if (function_exists('xdebug_set_filter')) {
    xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, array(
      '/var/www/html/sites/all/modules/vaxia/',
      '/var/www/html/sites/all/modules/patched/',
    ));
  }
  xdebug_start_code_coverage();
  // Registered from inside a shutdown function so it runs last, after
  // Drupal's own shutdown work (sessions, cron, hook_exit).
  register_shutdown_function(function () {
    register_shutdown_function(function () {
      $data = xdebug_get_code_coverage();
      xdebug_stop_code_coverage();
      $hits = array();
      foreach ($data as $file => $lines) {
        if (strpos($file, '/sites/all/modules/vaxia/') === FALSE && strpos($file, '/sites/all/modules/patched/') === FALSE) {
          continue;
        }
        foreach ($lines as $line => $count) {
          if ($count > 0) {
            $hits[$file][$line] = 1;
          }
        }
      }
      if (!$hits) {
        return;
      }
      $dir = '/var/www/tests/.build/coverage/raw';
      if (!is_dir($dir)) {
        @mkdir($dir, 0777, TRUE);
      }
      $fp = fopen($dir . '/' . PHP_SAPI . '-' . getmypid() . '.ser', 'c+');
      if (!$fp) {
        return;
      }
      flock($fp, LOCK_EX);
      $old = stream_get_contents($fp);
      $all = $old ? unserialize($old) : array();
      foreach ($hits as $file => $lines) {
        $all[$file] = isset($all[$file]) ? $all[$file] + $lines : $lines;
      }
      ftruncate($fp, 0);
      rewind($fp);
      fwrite($fp, serialize($all));
      fflush($fp);
      flock($fp, LOCK_UN);
      fclose($fp);
    });
  });
}
