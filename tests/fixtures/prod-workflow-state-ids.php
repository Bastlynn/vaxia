<?php

/**
 * @file
 * Renumbers workflow states to the production state ids (sids) that the exported
 * Rules hardcode (e.g. "new_state": {"29": "29"} = scene ruled). A fresh install
 * numbers states in import order, so the rules would watch the wrong states.
 *
 * Also removes duplicate states: workflow 7.x-1.1's importer re-creates a
 * workflow's states when it is reverted twice (vaxia_enable() plus drush fra).
 *
 * Run with: drush scr /var/www/tests/fixtures/prod-workflow-state-ids.php
 * (install.sh runs it after reverting features).
 *
 * Ids confirmed against a 2017-07-10 production backup (`workflow_states`).
 * The character workflow comes from the character_sheet contrib module, which
 * production customised: its states and transitions are taken from the backup
 * too (no VAXIA code exports them). The other workflows' transitions come from
 * the code's exports (newer than the backup); see prod-workflow-transitions.php.
 */

$prod = array(
  'Character evaluation' => array(
    '(creation)' => 1, 'draft' => 2, 'submitted' => 3, 'approved' => 4,
    'disapproved' => 5, 'inactive' => 6, 'xp_transferred' => 23,
    // character_sheet ships "removed from game"; production renamed it.
    'removed_from_game' => 24,
  ),
  'Art evaluation' => array(
    // Production also has draft (8) and disapproved (11), both disabled.
    '(creation)' => 7, 'approved' => 10, 'removed from site' => 17,
  ),
  'Item evaluation' => array(
    '(creation)' => 12, 'draft' => 13, 'submitted' => 14, 'approved' => 15,
    'disapproved' => 16, 'needs recharge' => 21, 'removed from game' => 22,
    'needs daily refresh' => 25,
  ),
  'Wiki workflow' => array(
    '(creation)' => 18, 'open' => 19, 'locked' => 20,
  ),
  'Scene evaluation' => array(
    '(creation)' => 26, 'draft' => 27, 'submitted' => 28, 'ruled' => 29,
    'not for submission' => 30,
  ),
  'Notation approval' => array(
    '(creation)' => 31, 'strike submitted' => 32, 'strike approved' => 33,
    'strike NOT approved' => 34, 'greenie / no strike' => 35,
    'greenie NOT approved' => 36,
  ),
  'Event status' => array(
    '(creation)' => 37, 'scheduled' => 38, 'cancelled' => 39,
  ),
);

// Production character workflow transitions (from-sid, to-sid, roles), from the
// 2017 backup. Role ids are production ids (prod-role-ids.php runs first).
$character_transitions = array(
  array(1, 2, 'author,3'), array(1, 3, 'author,3'), array(1, 4, '3'), array(1, 5, '3'),
  array(1, 6, '3'), array(1, 23, '3'), array(1, 24, '3'),
  array(2, 3, 'author,3,4,14,9'), array(2, 4, '3,4'), array(2, 5, '3,4'), array(2, 6, '3,4'),
  array(2, 23, '3'), array(2, 24, '3'),
  array(3, 2, '3,4,14,9,author'), array(3, 4, '3,4,14,9'), array(3, 5, '3,4,14,9'),
  array(3, 6, '3,4'), array(3, 23, '3'), array(3, 24, '3'),
  array(4, 3, '3,4,9,14'), array(4, 6, '3,4,author,14,9'), array(4, 2, '3,4,14,9'),
  array(4, 5, '3'), array(4, 23, '3'), array(4, 24, '3'),
  array(5, 3, '3,4'), array(5, 2, '3,4'), array(5, 4, '3,4'), array(5, 6, '3,4'),
  array(5, 23, '3'), array(5, 24, '3'),
  array(6, 3, '3,4,9'), array(6, 2, '3,4,14,author,9'), array(6, 4, '3,4,14'),
  array(6, 5, '3,4'), array(6, 23, '3'), array(6, 24, '3'),
  array(23, 2, '4,3'), array(23, 3, '4,3'), array(23, 4, '4,3'), array(23, 5, '4,3'),
  array(23, 6, '4,3'), array(23, 24, '3'),
  array(24, 2, '4,3'), array(24, 3, '4,3'), array(24, 4, '4,3'), array(24, 5, '4,3'),
  array(24, 6, '4,3'), array(24, 23, '3'),
);

$sid_tables = array(
  'workflow_transitions' => array('sid', 'target_sid'),
  'workflow_node' => array('sid'),
  'workflow_node_history' => array('old_sid', 'sid'),
  'workflow_scheduled_transition' => array('old_sid', 'sid'),
);

$repoint = function ($from, $to) use ($sid_tables) {
  foreach ($sid_tables as $table => $cols) {
    if (!db_table_exists($table)) {
      continue;
    }
    foreach ($cols as $col) {
      db_update($table)->fields(array($col => $to))->condition($col, $from)->execute();
    }
  }
};

$txn = db_transaction();

// 1. Collapse duplicate states (same workflow + name) onto the active copy. The
// importer soft-deletes (status 0) the copy it replaces, so keep status 1.
$dups = db_query('SELECT wid, state, GROUP_CONCAT(sid ORDER BY status DESC, sid ASC) AS sids FROM {workflow_states} GROUP BY wid, state HAVING COUNT(*) > 1');
foreach ($dups as $d) {
  $d->keep_sid = (int) strtok($d->sids, ',');
  foreach (explode(',', $d->sids) as $sid) {
    if ((int) $sid !== (int) $d->keep_sid) {
      $repoint($sid, $d->keep_sid);
      db_delete('workflow_states')->condition('sid', $sid)->execute();
    }
  }
}
// Transitions made identical by the collapse.
db_query('DELETE t1 FROM {workflow_transitions} t1 JOIN {workflow_transitions} t2 ON t1.sid = t2.sid AND t1.target_sid = t2.target_sid AND t1.tid > t2.tid');

// Character workflow: production's state names.
$char_wid = db_query("SELECT wid FROM {workflows} WHERE name = 'Character evaluation'")->fetchField();
db_update('workflow_states')->fields(array('state' => 'removed_from_game'))
  ->condition('wid', $char_wid)->condition('state', 'removed from game')->execute();
$weights = array('inactive' => -15, 'xp_transferred' => -14, 'removed_from_game' => -13);
foreach ($weights as $name => $weight) {
  $exists = db_query('SELECT sid FROM {workflow_states} WHERE wid = :w AND state = :s', array(':w' => $char_wid, ':s' => $name))->fetchField();
  if (!$exists) {
    db_insert('workflow_states')->fields(array('wid' => $char_wid, 'state' => $name, 'weight' => $weight, 'sysid' => 0, 'status' => 1))->execute();
  }
}

// 2. Move every state out of the way, then onto its production id.
$offset = 1000;
$states = db_query('SELECT s.sid, s.state, w.name FROM {workflow_states} s JOIN {workflows} w ON w.wid = s.wid')->fetchAll();
foreach ($states as $s) {
  db_update('workflow_states')->fields(array('sid' => $s->sid + $offset))->condition('sid', $s->sid)->execute();
  $repoint($s->sid, $s->sid + $offset);
}
$next = 100;
foreach ($states as $s) {
  $target = isset($prod[$s->name][$s->state]) ? $prod[$s->name][$s->state] : $next++;
  if (!isset($prod[$s->name][$s->state])) {
    drush_log("No production id for '{$s->name}' / '{$s->state}', using $target", 'warning');
  }
  db_update('workflow_states')->fields(array('sid' => $target))->condition('sid', $s->sid + $offset)->execute();
  $repoint($s->sid + $offset, $target);
}
$inactive = db_query('SELECT COUNT(*) FROM {workflow_states} WHERE status = 0')->fetchField();
if ($inactive) {
  drush_log("$inactive workflow states are inactive (status 0)", 'warning');
}
// 3. Who sees each workflow's tab, and its options, as in production.
$tab_roles = array(
  'Character evaluation' => 'author,3,9',
  'Art evaluation' => 'author,3',
  'Item evaluation' => 'author,3,9',
  'Wiki workflow' => '14,4,3',
  'Scene evaluation' => 'author,3,9',
  'Notation approval' => '21,29',
  'Event status' => 'author,3,21',
);
$log_all = array('comment_log_node' => '1', 'comment_log_tab' => '1', 'name_as_title' => '1', 'watchdog_log' => '1');
$options = array(
  'Notation approval' => array('comment_log_node' => '1', 'comment_log_tab' => '1', 'name_as_title' => '0', 'watchdog_log' => '0'),
  'Event status' => array('comment_log_node' => '0', 'comment_log_tab' => '0', 'name_as_title' => '1', 'watchdog_log' => '0'),
);
foreach ($tab_roles as $name => $roles) {
  db_update('workflows')->fields(array(
    'tab_roles' => $roles,
    'options' => serialize(isset($options[$name]) ? $options[$name] : $log_all),
  ))->condition('name', $name)->execute();
}

// 4. Character transitions: production's set.
db_delete('workflow_transitions')->condition('sid', array_values($prod['Character evaluation']), 'IN')->execute();
foreach ($character_transitions as $t) {
  db_insert('workflow_transitions')->fields(array('sid' => $t[0], 'target_sid' => $t[1], 'roles' => $t[2]))->execute();
}
unset($txn);

db_query('ALTER TABLE {workflow_states} AUTO_INCREMENT = 200');
cache_clear_all();
foreach (db_query('SELECT s.sid, w.name, s.state FROM {workflow_states} s JOIN {workflows} w ON w.wid = s.wid ORDER BY s.sid') as $r) {
  drush_print($r->sid . "\t" . $r->name . "\t" . $r->state);
}
