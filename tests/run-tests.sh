#!/bin/bash
# Runs the VAXIA PHPUnit suite inside the docker web container.
#   tests/run-tests.sh                                 # everything except @group knownbug
#   tests/run-tests.sh modules/rpg/strikes             # one module
#   tests/run-tests.sh --group knownbug                # only the tests that pin known bugs
# First time: (cd tests/docker && docker compose up -d --build && ./fetch-contrib.sh && ./install.sh)
cd "$(dirname "$0")/docker" || exit 1
# Fresh error log per run (PHP notices and Rules errors seen during tests).
rm -f ../.build/php-errors.log
args=()
for a in "$@"; do
  if [ -e "../$a" ]; then a="/var/www/tests/$a"; fi
  args+=("$a")
done
exec docker compose exec -T -w /var/www/tests web phpunit -c /var/www/tests/phpunit.xml "${args[@]}"
