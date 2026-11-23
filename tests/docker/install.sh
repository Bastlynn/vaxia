#!/bin/bash
# Fresh Drupal 7 install with every VAXIA custom module enabled, then a DB snapshot
# (tests/.build/baseline.sql) that each test class restores before it runs.
#   tests/docker/install.sh             # all modules in modules.txt
#   tests/docker/install.sh strikes     # only the listed modules (plus dependencies)
set -eo pipefail
cd "$(dirname "$0")"

# Install-blocking bugs in the modules are worked around by patches applied only for
# the install and always reverted (see fixtures/install-patches/*.patch headers).
repo=$(cd ../.. && pwd)
patches=(../fixtures/install-patches/*.patch)
revert() { for ((i=${#patches[@]}-1; i>=0; i--)); do patch -s -R -p1 -d "$repo" < "${patches[i]}" || true; done; }
for pf in "${patches[@]}"; do patch -s -p1 -d "$repo" < "$pf"; done
trap revert EXIT

modules="${*:-$(grep -v '^#' modules.txt | tr '\n' ' ')}"

docker compose exec -T -e MODULES="$modules" web bash -s <<'SCRIPT'
set -eo pipefail
cd /var/www/html
chmod -R u+w sites/default
rm -f sites/default/settings.php
drush -y si standard --db-url=mysql://vaxia:vaxia@db/vaxia --account-name=admin --account-pass=admin --site-name=VAXIA-test
mkdir -p sites/default/files/private
chmod -R 777 sites/default/files
drush ev "variable_set('file_private_path', 'sites/default/files/private');"

# Undeclared dependencies: rules call smart_ip; the "forums" vocabulary and
# taxonomy_forums fields belong to core forum (elections post into it); views
# filter on workflow state, which needs workflow_views (without it Views drops
# those filters silently); views and storyhost_duties query privatemsg's tables
# (production also ran pm_email_notify and pm_block_user); comment_revisions_diff
# needs comment_revisions and diff.
drush -y en smart_ip forum workflow_views privatemsg pm_email_notify pm_block_user comment_revisions diff

for m in $MODULES; do
  echo "== enable $m"
  drush -y en "$m"
done

# Register base themes (zen) explicitly; otherwise list_themes() can miss them and
# every theme-aware alter hook hits a NULL base theme.
drush ev 'system_rebuild_theme_data(); drupal_theme_rebuild();'
drush -y en vaxia_theme
drush ev "variable_set('theme_default', 'vaxia_theme');"

# Production role ids, which the exported rules and views hardcode.
drush scr /var/www/tests/fixtures/prod-role-ids.php

# Settings production has but no feature exports.
drush scr /var/www/tests/fixtures/prod-settings.php

# Fields production has but no feature exports.
drush scr /var/www/tests/fixtures/prod-missing-fields.php

# Realm terms at the production term ids code hardcodes (725, 726, 1004).
drush scr /var/www/tests/fixtures/prod-realm-terms.php

# Per the README: reverting features creates the workflows a plain enable misses.
drush -y fra

# Transitions workflow 1.2's importer loses (before renumbering states).
drush scr /var/www/tests/fixtures/prod-workflow-transitions.php

# Production workflow state ids, which the exported rules hardcode.
drush scr /var/www/tests/fixtures/prod-workflow-state-ids.php

# Production payment method ids and donation block settings.
drush scr /var/www/tests/fixtures/prod-payment.php

# Production role permissions (after the revert, which resets exported ones).
drush scr /var/www/tests/fixtures/prod-role-permissions.php

# Test settings: no page cache or aggregation (tests read pages right after writing),
# and mail goes to the drupal_test_email_collector variable so tests can read it.
drush ev "variable_set('cache', 0); variable_set('preprocess_css', 0); variable_set('preprocess_js', 0);"
drush ev "variable_set('mail_system', array('default-system' => 'TestingMailSystem'));"
drush ev 'node_access_rebuild();'
drush cc all

mysqldump -hdb -uvaxia -pvaxia vaxia > /var/www/tests/.build/baseline.sql
echo "== baseline written"
SCRIPT
