<?php

/**
 * @file
 * Renumbers roles to the production role ids that the exported Rules hardcode
 * (e.g. "user_add_role": {"30": "30"}). A fresh install numbers roles in enable
 * order, so without this the rules add/remove/check the wrong roles or crash.
 *
 * Run with: drush scr /var/www/tests/fixtures/prod-role-ids.php
 * install.sh runs it after enabling modules and BEFORE reverting features, so the
 * workflow transitions created by the revert pick up the final ids.
 *
 * Ids confirmed against a 2017-07-10 production backup (`role`). Ids the rules
 * use that were already gone by then get placeholder names.
 */

$prod = array(
  3 => 'administrator',
  8 => 'player',                         // "Notify player when char approved" adds 8.
  9 => 'storyteller',                    // "SH Test - Promote to Storyhost" adds 9.
  10 => 'storyteller-assistant',         // "Promote to ASH 2" adds 10.
  12 => 'eligible for SH',               // "Make user eligible for SH on XP gain" adds 12.
  13 => 'storyteller-newbie',            // "Promote to ASH 1" adds 13.
  15 => 'storyteller-expired',           // "SH Renewal - Expire Storyhost" adds 15.
  17 => 'storyteller-candidate',         // "Promote to Storyhost" removes 17.
  19 => 'voter',                         // "Proposal - Add voter" adds 19.
  21 => 'lead',                          // "Department - Promote temporary lead" adds 21.
  22 => 'cold storage',                  // "Set blocked players to cold storage" adds 22.
  23 => 'vacation mode',                 // "Vacation - Starting vacation" adds 23.
  24 => 'knows-the-system',
  26 => 'knows-the-social',
  27 => 'knows-the-setting',
  28 => 'newbie-helper',
  29 => 'mediator',
  34 => 'eligible for mediator',
  35 => 'eligible for newbie helper',
  25 => 'starvation-mode-ash-approver',  // "Add starvation role to ASH on session" adds 25.
  30 => 'new account',                   // "Set new account role on new accounts" adds 30; no feature exports it.
  31 => 'banned user',                   // "Bans - Add IP for banned user" checks 31.
  32 => 'suspended user',                // "Suspend - Red Strike" adds 32.
  33 => 'shadowbanned user',             // "Vaxia Shadowban User" checks 33 (added after 2017).
);
// Ids the rules (and production transitions) mention that no longer existed in
// 2017; placeholders keep the rules from hitting undefined roles.
$unknown = array(4, 7, 11, 14, 20);
foreach ($unknown as $rid) {
  $prod[$rid] = 'prod-role-' . $rid;
}

// Every table keyed on role id (webform_conditional_rules.rid is a rule id, not a role).
$tables = array('role', 'role_permission', 'users_roles', 'block_role', 'role_expire', 'role_expire_length', 'user_badges_roles', 'webform_roles');
$tables = array_filter($tables, 'db_table_exists');

$txn = db_transaction();
// Move every non-core role out of the way first so target ids are free.
$offset = 1000;
$existing = db_query('SELECT rid, name FROM {role} WHERE rid > 2')->fetchAllKeyed();
foreach (array_keys($existing) as $rid) {
  foreach ($tables as $table) {
    db_update($table)->fields(array('rid' => $rid + $offset))->condition('rid', $rid)->execute();
  }
}
$by_name = array();
foreach ($existing as $rid => $name) {
  $by_name[$name] = $rid + $offset;
}

$next = 100;
foreach ($prod as $rid => $name) {
  if (isset($by_name[$name])) {
    foreach ($tables as $table) {
      db_update($table)->fields(array('rid' => $rid))->condition('rid', $by_name[$name])->execute();
    }
    unset($by_name[$name]);
  }
  else {
    db_insert('role')->fields(array('rid' => $rid, 'name' => $name, 'weight' => $rid))->execute();
  }
}
// Roles the rules never reference keep their names, renumbered from 100.
foreach ($by_name as $name => $tmp) {
  foreach ($tables as $table) {
    db_update($table)->fields(array('rid' => $next))->condition('rid', $tmp)->execute();
  }
  $next++;
}
unset($txn);

db_query('ALTER TABLE {role} AUTO_INCREMENT = 200');
drupal_static_reset();
cache_clear_all();
foreach (db_query('SELECT rid, name FROM {role} ORDER BY rid') as $r) {
  drush_print($r->rid . "\t" . $r->name);
}
