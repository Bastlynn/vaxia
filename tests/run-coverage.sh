#!/bin/bash
# Line coverage of VAXIA code for the black-box suite.
#   tests/run-coverage.sh                      # whole suite, known-bug tests included
#   tests/run-coverage.sh modules/rpg/strikes  # one module (PHPUnit arguments, as run-tests.sh)
# Restarts the web container with Xdebug coverage on; every PHP process in it
# (Apache requests, the PHPUnit process) records the VAXIA lines it runs while
# tests/.build/coverage/ON exists
# (tests/coverage/prepend.php). Reports: tests/.build/coverage/report/
# (modules.txt, summary.txt, clover.xml, html/index.html).
here=$(cd "$(dirname "$0")" && pwd)
cov="$here/.build/coverage"
rm -rf "$cov"
mkdir -p "$cov/raw"
chmod 777 "$cov" "$cov/raw"
touch "$cov/ON"
# Xdebug only collects in coverage mode; restart the web container in it, and
# back to normal at the end.
restore() {
  rm -f "$cov/ON"
  (cd "$here/docker" && XDEBUG_MODE=off docker compose up -d web >/dev/null 2>&1)
}
trap restore EXIT
(cd "$here/docker" && XDEBUG_MODE=coverage docker compose up -d web) || exit 1
until (cd "$here/docker" && docker compose exec -T web curl -fsS -o /dev/null http://localhost/user/login 2>/dev/null); do sleep 1; done

status=0
if [ $# -gt 0 ]; then
  "$here/run-tests.sh" "$@" || status=$?
else
  "$here/run-tests.sh" || status=$?
  # Known-bug tests exercise real code too (they fail by design).
  "$here/run-tests.sh" --group knownbug > "$cov/knownbug.txt" 2>&1 || true
fi
rm -f "$cov/ON"

cd "$here/docker" || exit 1
docker compose exec -T -e XDEBUG_MODE=off web php /var/www/tests/coverage/report.php /var/www/tests/.build/coverage/raw /var/www/tests/.build/coverage/report
echo "HTML report: tests/.build/coverage/report/html/index.html"
exit $status
