<?php

/**
 * @file
 * Recreates workflow transitions from the exported default workflows.
 *
 * Production runs workflow 7.x-1.2 or later: VAXIA code reads $node->workflow and
 * $node->workflow_state_name, which 1.1 never loads. But 1.2's features importer
 * deletes every transition it creates (workflow_update_workflow_transitions()
 * returns FALSE for new rows, so they look stale). Production had its
 * transitions, so recreate them here from the same exports.
 *
 * Run with: drush scr /var/www/tests/fixtures/prod-workflow-transitions.php
 * (install.sh runs it after reverting features, before renumbering states).
 */

module_load_include('inc', 'workflow', 'workflow.features');
$created = 0;
foreach (module_implements('workflow_default_workflows') as $module) {
  $defaults = module_invoke($module, 'workflow_default_workflows');
  foreach ((array) $defaults as $export) {
    $wid = db_query('SELECT wid FROM {workflows} WHERE name = :n', array(':n' => $export['name']))->fetchField();
    if (!$wid) {
      drush_log("No workflow '{$export['name']}'", 'warning');
      continue;
    }
    // Active state ids by name (the importer soft-deletes replaced copies).
    $sids = db_query('SELECT state, sid FROM {workflow_states} WHERE wid = :wid AND status = 1', array(':wid' => $wid))->fetchAllKeyed();
    foreach ((array) $export['transitions'] as $t) {
      if (!isset($sids[$t['state']], $sids[$t['target_state']])) {
        drush_log("Missing state for {$export['name']}: {$t['state']} -> {$t['target_state']}", 'warning');
        continue;
      }
      $exists = db_query('SELECT tid FROM {workflow_transitions} WHERE sid = :s AND target_sid = :t',
        array(':s' => $sids[$t['state']], ':t' => $sids[$t['target_state']]))->fetchField();
      if ($exists) {
        continue;
      }
      db_insert('workflow_transitions')->fields(array(
        'sid' => $sids[$t['state']],
        'target_sid' => $sids[$t['target_state']],
        'roles' => !empty($t['roles']) ? _workflow_roles_to_rids($t['roles']) : '',
      ))->execute();
      $created++;
    }
  }
}
drush_print("Created $created workflow transitions.");
