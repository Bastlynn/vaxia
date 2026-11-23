<?php

/**
 * @file
 * Seeds the Realm vocabulary with the production terms at their production term
 * ids. Code hardcodes them: field defaults and view filters use 725 (Vaxia) and
 * 726 (Sirian), and vaxia.module and the storyhost rules use 1004 (Storyhost).
 * Ids and names are from a 2017-07-10 production backup (`taxonomy_term_data`).
 *
 * Run with: drush scr /var/www/tests/fixtures/prod-realm-terms.php
 */

$prod = array(725 => array('Vaxia', 1), 726 => array('Sirian', 2), 1004 => array('Storyhost', 0));
$vid = taxonomy_vocabulary_machine_name_load('realm')->vid;

$txn = db_transaction();
foreach ($prod as $tid => $info) {
  list($name, $weight) = $info;
  $existing = db_query('SELECT tid FROM {taxonomy_term_data} WHERE vid = :vid AND name = :name', array(':vid' => $vid, ':name' => $name))->fetchField();
  if ($existing && (int) $existing !== $tid) {
    // Repoint every term reference, then the term itself.
    foreach (field_info_fields() as $field) {
      if ($field['type'] === 'taxonomy_term_reference') {
        foreach (array('field_data_', 'field_revision_') as $prefix) {
          db_update($prefix . $field['field_name'])->fields(array($field['field_name'] . '_tid' => $tid))
            ->condition($field['field_name'] . '_tid', $existing)->execute();
        }
      }
    }
    foreach (array('taxonomy_term_data', 'taxonomy_term_hierarchy', 'taxonomy_index') as $table) {
      db_update($table)->fields(array('tid' => $tid))->condition('tid', $existing)->execute();
    }
  }
  elseif (!$existing) {
    db_insert('taxonomy_term_data')->fields(array('tid' => $tid, 'vid' => $vid, 'name' => $name, 'description' => '', 'format' => 'plain_text', 'weight' => $weight))->execute();
    db_insert('taxonomy_term_hierarchy')->fields(array('tid' => $tid, 'parent' => 0))->execute();
  }
  db_update('taxonomy_term_data')->fields(array('weight' => $weight))->condition('tid', $tid)->execute();
}
unset($txn);

db_query('ALTER TABLE {taxonomy_term_data} AUTO_INCREMENT = 2000');
entity_get_controller('taxonomy_term')->resetCache();
cache_clear_all();
foreach (db_query('SELECT tid, name FROM {taxonomy_term_data} WHERE vid = :vid ORDER BY tid', array(':vid' => $vid)) as $r) {
  drush_print($r->tid . "\t" . $r->name);
}
