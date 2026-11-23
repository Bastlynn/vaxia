<?php

/**
 * @file
 * PHPUnit bootstrap: boots the Drupal 7 test site in-process so tests can create
 * fixtures with the Drupal API, then check behaviour over HTTP against Apache.
 *
 * Runs inside the "web" container (see docker/docker-compose.yml).
 */

define('DRUPAL_ROOT', getenv('DRUPAL_ROOT') ?: '/var/www/html');
define('VAXIA_TESTS_ROOT', __DIR__);
define('VAXIA_TEST_BASE_URL', getenv('VAXIA_TEST_BASE_URL') ?: 'http://localhost');
define('VAXIA_BASELINE_SQL', __DIR__ . '/.build/baseline.sql');

if (!is_file(VAXIA_BASELINE_SQL)) {
  fwrite(STDERR, "Missing " . VAXIA_BASELINE_SQL . ": run tests/docker/install.sh first.\n");
  exit(1);
}

spl_autoload_register(function ($class) {
  $prefix = 'Vaxia\\Tests\\';
  if (strpos($class, $prefix) !== 0) {
    return;
  }
  $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($file)) {
    require $file;
  }
});

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_SOFTWARE'] = 'PHPUnit';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_USER_AGENT'] = 'phpunit';

chdir(DRUPAL_ROOT);
require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

// Drupal installs its own error handler, which turns notices into screen output.
// Hand errors back to PHPUnit, but only for VAXIA's own code: a notice raised in
// sites/all/modules/vaxia fails the test that hit it. Notices from core/contrib
// are production noise; they go to .build/php-errors.log instead.
// (PHPUnit 9 doesn't install its own handler when one is already set.)
restore_error_handler();
restore_exception_handler();
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
  if (!(error_reporting() & $errno) || $errno === E_DEPRECATED || $errno === E_USER_DEPRECATED || $errno === E_STRICT) {
    return TRUE;
  }
  if (strpos($errfile, '/sites/all/modules/vaxia/') !== FALSE || strpos($errfile, VAXIA_TESTS_ROOT) === 0) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
  }
  file_put_contents(VAXIA_TESTS_ROOT . '/.build/php-errors.log', "[in-process] $errstr in $errfile:$errline\n", FILE_APPEND);
  return TRUE;
});
