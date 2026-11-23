<?php

namespace Vaxia\Tests;

/**
 * The setup driver: the ONLY place tests may touch Drupal internals.
 *
 * Tests arrange state through this class, then act and assert over HTTP. When
 * the site moves to Drupal 10/11, re-implement these methods against the new
 * site (API, drush, or admin UI) and the tests themselves stay as they are.
 *
 * Methods here create content and accounts, change configuration that has no
 * admin form, and "time travel" (backdate records) where a behaviour depends on
 * time passing. Nothing here should assert behaviour.
 */
class Site {

  /** @var int Counter for unique names. */
  private static $seq = 0;

  /**
   * Unique, zero-padded name: no generated name is a substring of another.
   */
  public function uniqueName($prefix = 'vt') {
    return $prefix . '_' . getmypid() . '_' . str_pad((string) (++self::$seq), 5, '0', STR_PAD_LEFT);
  }

  // ---------------------------------------------------------------------------
  // Accounts.
  // ---------------------------------------------------------------------------

  /**
   * Creates an account.
   *
   * @param string[] $roles
   *   Role names.
   * @param string[] $permissions
   *   Extra permissions (granted through a throwaway role).
   * @param array $values
   *   Extra properties. Profile fields as 'field_x' => scalar value.
   *
   * @return object
   *   Account with uid, name, mail and pass_raw.
   */
  public function createUser(array $roles = array(), array $permissions = array(), array $values = array()) {
    $name = $values['name'] ?? $this->uniqueName('user');
    $rids = array(DRUPAL_AUTHENTICATED_RID => 'authenticated user');
    foreach ($roles as $role_name) {
      $role = user_role_load_by_name($role_name);
      if (!$role) {
        throw new \InvalidArgumentException("No role '$role_name'");
      }
      $rids[$role->rid] = $role->name;
    }
    if ($permissions) {
      $role = (object) array('name' => $this->uniqueName('role'));
      user_role_save($role);
      user_role_grant_permissions($role->rid, $permissions);
      $rids[$role->rid] = $role->name;
    }
    $edit = array(
      'name' => $name,
      'mail' => $values['mail'] ?? $name . '@example.test',
      'pass' => 'pass',
      'status' => $values['status'] ?? 1,
      'roles' => $rids,
    );
    if (isset($values['created'])) {
      $edit['created'] = $values['created'];
    }
    // Registered players have ticked both required boxes (unticked age blocks
    // the account: "User is under minimal age").
    $values += array('field_age_requirement' => 1, 'field_terms_and_conditions' => 1);
    foreach ($values as $key => $value) {
      if (strpos($key, 'field_') === 0) {
        $edit[$key] = $this->fieldItems($key, $value);
      }
    }
    $account = user_save(drupal_anonymous_user(), $edit);
    $this->resetCaches();
    return (object) array('uid' => (int) $account->uid, 'name' => $account->name, 'mail' => $account->mail, 'pass_raw' => 'pass');
  }

  public function grantPermissions($roleName, array $permissions) {
    user_role_grant_permissions(user_role_load_by_name($roleName)->rid, $permissions);
  }

  public function revokePermissions($roleName, array $permissions) {
    user_role_revoke_permissions(user_role_load_by_name($roleName)->rid, $permissions);
  }

  /**
   * Whether a role holds a permission now.
   */
  public function roleHas($roleName, $permission) {
    $rid = user_role_load_by_name($roleName)->rid;
    return (bool) db_query('SELECT 1 FROM {role_permission} WHERE rid = :rid AND permission = :p', array(':rid' => $rid, ':p' => $permission))->fetchField();
  }

  public function createRole($name = NULL) {
    $role = (object) array('name' => $name ?: $this->uniqueName('role'));
    user_role_save($role);
    return $role->name;
  }

  public function addRole($account, $roleName) {
    user_multiple_role_edit(array($account->uid), 'add_role', user_role_load_by_name($roleName)->rid);
    $this->resetCaches();
  }

  public function removeRole($account, $roleName) {
    user_multiple_role_edit(array($account->uid), 'remove_role', user_role_load_by_name($roleName)->rid);
    $this->resetCaches();
  }

  /**
   * Takes a role away from every account (isolates tests sharing a database).
   */
  public function removeRoleFromEveryone($roleName) {
    db_delete('users_roles')->condition('rid', user_role_load_by_name($roleName)->rid)->execute();
  }

  public function roleId($roleName) {
    return (int) user_role_load_by_name($roleName)->rid;
  }

  // ---------------------------------------------------------------------------
  // Content.
  // ---------------------------------------------------------------------------

  /**
   * Creates a node. Field values may be given as scalars ('field_x' => 5) or
   * in full Drupal item form.
   */
  public function createNode($type, array $values = array()) {
    $node = (object) array(
      'type' => $type,
      'title' => $values['title'] ?? $this->uniqueName($type),
      'uid' => $values['uid'] ?? 1,
      'status' => $values['status'] ?? 1,
      'language' => LANGUAGE_NONE,
      'comment' => 2,
    );
    node_object_prepare($node);
    // node_object_prepare() resets the author to the current user; put it back.
    $node->uid = $values['uid'] ?? 1;
    $node->name = user_load($node->uid)->name;
    foreach ($values as $key => $value) {
      if (strpos($key, 'field_') === 0 || $key === 'body' || $key === 'taxonomy_forums') {
        $node->$key = $this->fieldItems($key, $value);
      }
      elseif (!in_array($key, array('title', 'uid', 'status'), TRUE)) {
        $node->$key = $value;
      }
    }
    node_save($node);
    $this->resetCaches();
    return (object) array('nid' => (int) $node->nid, 'title' => $node->title, 'uid' => (int) $node->uid, 'type' => $type);
  }

  /**
   * Sets profile fields on an existing account (values as in createNode()).
   */
  public function setUserFields($account, array $values) {
    $loaded = user_load($account->uid, TRUE);
    $edit = array();
    foreach ($values as $key => $value) {
      $edit[$key] = $this->fieldItems($key, $value);
    }
    user_save($loaded, $edit);
    drupal_get_messages();
    $this->resetCaches();
  }

  /**
   * Saves changes to an existing node as $account (default: its author), the
   * way an edit form would. Field values as in createNode().
   */
  public function saveNode($node, array $changes, $account = NULL) {
    $loaded = node_load($node->nid, NULL, TRUE);
    foreach ($changes as $key => $value) {
      $loaded->$key = strpos($key, 'field_') === 0 ? $this->fieldItems($key, $value) : $value;
    }
    $this->runAs($account ?: user_load($loaded->uid), function () use ($loaded) {
      node_save($loaded);
    });
    drupal_get_messages();
    $this->resetCaches();
  }

  /**
   * A player character (not NPC / storyteller) in realm "Vaxia" unless overridden.
   */
  public function createCharacter($owner, array $values = array()) {
    return $this->createNode('character_sheet', $values + array(
      'uid' => $owner->uid,
      'field_is_npc' => 0,
      'field_is_open_npc' => 0,
      'field_is_storyteller' => 0,
      'field_is_generic_npc' => 0,
      'field_tag_realm' => $this->term('realm', 'Vaxia'),
    ));
  }

  public function createRoom(array $values = array()) {
    return $this->createNode('rpg_chatroom', $values);
  }

  public function createNotation($player, array $values = array()) {
    return $this->createNode('notation', $values + array(
      'field_players' => array('uid' => $player->uid),
      'field_date_of_incident' => date('Y-m-d H:i:s'),
    ));
  }

  /**
   * Posts a chat comment, optionally as a character.
   */
  public function postComment($node, $author, $text, $character = NULL) {
    $comment = (object) array(
      'nid' => $node->nid, 'cid' => 0, 'pid' => 0, 'uid' => $author->uid, 'name' => $author->name,
      'subject' => substr($text, 0, 20), 'status' => COMMENT_PUBLISHED, 'language' => LANGUAGE_NONE,
      // comment_revisions stores a revision only when asked (the comment form does).
      'revision' => 1,
      'comment_body' => array(LANGUAGE_NONE => array(array('value' => $text, 'format' => 'plain_text'))),
    );
    if ($character) {
      $comment->field_comment_character = array(LANGUAGE_NONE => array(array('nid' => $character->nid)));
    }
    comment_save($comment);
    $this->resetCaches();
    return (object) array('cid' => (int) $comment->cid, 'nid' => (int) $node->nid);
  }

  /**
   * A scene record listing the given posts (in the order given).
   */
  public function createScene($author, array $comments) {
    $refs = array();
    foreach ($comments as $c) {
      $refs[] = array('target_id' => $c->cid);
    }
    return $this->createNode('scene', array('uid' => $author->uid, 'field_rp_posts' => $refs));
  }

  /**
   * Adds a skill (a field_skill collection item) to a character; $aspects are
   * skill_verbs term names ("craft", "repair"). Returns the skill's id, which is
   * its value in the dice roller's skill selects.
   */
  public function addSkill($character, $name, $value, array $aspects = array()) {
    $node = node_load($character->nid, NULL, TRUE);
    $item = entity_create('field_collection_item', array('field_name' => 'field_skill'));
    $item->setHostEntity('node', $node);
    $item->field_skill_name[LANGUAGE_NONE][0]['value'] = $name;
    $item->field_skill_value[LANGUAGE_NONE][0]['value'] = $value;
    foreach (array_values($aspects) as $i => $aspect) {
      $field = array('field_aspect_one', 'field_aspect_two', 'field_aspect_three')[$i];
      $item->{$field}[LANGUAGE_NONE][0]['tid'] = $this->term('skill_verbs', $aspect);
    }
    $item->save();
    $this->resetCaches();
    return (int) $item->item_id;
  }

  /**
   * Places a block in the site theme, shown only on $pages (one path per line).
   */
  public function placeBlock($module, $delta, $region, $pages = '') {
    $theme = variable_get('theme_default', 'bartik');
    db_merge('block')->key(array('theme' => $theme, 'module' => $module, 'delta' => $delta))
      ->fields(array('status' => 1, 'region' => $region, 'weight' => -46, 'visibility' => $pages === '' ? 0 : 1, 'pages' => $pages, 'title' => '<none>', 'cache' => DRUPAL_NO_CACHE))
      ->execute();
    cache_clear_all();
  }

  /**
   * Deletes every node of a type (for features that pick among all of them).
   */
  public function deleteNodesOfType($type) {
    node_delete_multiple(db_query('SELECT nid FROM {node} WHERE type = :t', array(':t' => $type))->fetchCol());
  }

  /**
   * A managed (uploaded) public file, as a file field item for createNode().
   */
  public function createFile($filename, $contents = 'test') {
    $file = file_save_data($contents, 'public://' . $filename, FILE_EXISTS_RENAME);
    return array('fid' => (int) $file->fid, 'display' => 1, 'description' => '');
  }

  /**
   * A term at a fixed term id (code that hardcodes production term ids).
   */
  public function createTermAt($vocabulary, $tid, $name) {
    if (!taxonomy_term_load($tid)) {
      $vid = taxonomy_vocabulary_machine_name_load($vocabulary)->vid;
      db_insert('taxonomy_term_data')->fields(array('tid' => $tid, 'vid' => $vid, 'name' => $name, 'description' => '', 'format' => 'plain_text', 'weight' => 0))->execute();
      db_insert('taxonomy_term_hierarchy')->fields(array('tid' => $tid, 'parent' => 0))->execute();
      $this->resetCaches();
    }
    return $tid;
  }

  /**
   * Forgets every dice roll ever made (a site that has seen none).
   */
  public function deleteDiceRolls() {
    db_delete('dice_roller')->execute();
  }

  /**
   * A quiz with one multiple-choice question; $alternatives maps each answer
   * to TRUE when it's correct. Returns the quiz.
   */
  public function createMultichoiceQuiz($title, $question, array $alternatives) {
    $quiz = $this->createNode('quiz', array('title' => $title));
    $items = array();
    foreach ($alternatives as $answer => $correct) {
      $items[] = array(
        'answer' => array('value' => $answer, 'format' => 'plain_text'),
        'feedback_if_chosen' => array('value' => '', 'format' => 'plain_text'),
        'feedback_if_not_chosen' => array('value' => '', 'format' => 'plain_text'),
        'score_if_chosen' => $correct ? 1 : 0,
        'score_if_not_chosen' => 0,
        'correct' => $correct ? 1 : 0,
      );
    }
    $q = $this->createNode('multichoice', array(
      'title' => $question,
      'body' => array(array('value' => $question, 'format' => 'plain_text')),
      'choice_multi' => 0, 'choice_random' => 0, 'choice_boolean' => 0,
      'alternatives' => $items,
    ));
    $quiz_node = node_load($quiz->nid, NULL, TRUE);
    $q_node = node_load($q->nid, NULL, TRUE);
    db_insert('quiz_node_relationship')->fields(array(
      'parent_nid' => $quiz_node->nid, 'parent_vid' => $quiz_node->vid,
      'child_nid' => $q_node->nid, 'child_vid' => $q_node->vid,
      'question_status' => 1, 'weight' => 0, 'max_score' => 1,
    ))->execute();
    $this->resetCaches();
    return $quiz;
  }

  /**
   * Adds to a character's XP pool of a type (xp, rxp, bxp, kxp) directly.
   */
  public function addCharacterXp($character, $type, $amount) {
    module_load_include('module', 'character_sheet');
    _character_sheet_update_xp($character->nid, $amount, $type);
    $this->resetCaches();
  }

  /**
   * An account by name, for accounts made through forms (registration).
   */
  public function findUser($name) {
    $account = user_load_by_name($name);
    return $account ? (object) array('uid' => (int) $account->uid, 'name' => $account->name, 'mail' => $account->mail) : NULL;
  }

  /**
   * Term id by vocabulary and name, created if missing (vocabularies ship empty).
   */
  public function term($vocabulary, $name) {
    $found = taxonomy_get_term_by_name($name, $vocabulary);
    if ($found) {
      return (int) reset($found)->tid;
    }
    $vocab = taxonomy_vocabulary_machine_name_load($vocabulary);
    $term = (object) array('vid' => $vocab->vid, 'name' => $name);
    taxonomy_term_save($term);
    return (int) $term->tid;
  }

  /**
   * Adds a select question to a webform node (proposal ballots, return forms).
   */
  public function addWebformQuestion($node, $formKey, $label, array $options, $type = 'select') {
    module_load_include('inc', 'webform', 'includes/webform.components');
    $items = array();
    foreach ($options as $key => $text) {
      $items[] = $key . '|' . $text;
    }
    $component = array(
      'nid' => $node->nid, 'pid' => 0, 'form_key' => $formKey, 'name' => $label,
      'type' => $type, 'mandatory' => 1, 'weight' => 0, 'value' => '',
      'extra' => $type === 'select' ? array('items' => implode("\n", $items), 'multiple' => 0, 'aslist' => 1) : array(),
    );
    webform_component_insert($component);
    $this->resetCaches();
  }

  /**
   * Creates a webform node at a fixed nid (rules tied to production nids).
   */
  public function createWebformAt($nid, $title, array $roles, array $components) {
    if (node_load($nid)) {
      return;
    }
    $webform = webform_node_defaults();
    $webform['roles'] = $roles;
    $webform['components'] = $components;
    $node = (object) array(
      'nid' => $nid, 'is_new' => TRUE, 'type' => 'webform', 'title' => $title, 'uid' => 1,
      'status' => 1, 'language' => LANGUAGE_NONE, 'webform' => $webform,
    );
    node_save($node);
    $this->resetCaches();
  }

  /**
   * Closes every proposal ballot (isolates tests that share a database).
   */
  public function closeAllProposals() {
    db_query("UPDATE {webform} w JOIN {node} n ON n.nid = w.nid SET w.status = 0 WHERE n.type = 'proposal'");
  }

  public function setWebformOpen($node, $open) {
    db_update('webform')->fields(array('status' => $open ? 1 : 0))->condition('nid', $node->nid)->execute();
    $this->resetCaches();
  }

  /**
   * Gives a node's ballot to these roles (webform roles).
   */
  public function setWebformRoles($node, array $roleNames) {
    db_delete('webform_roles')->condition('nid', $node->nid)->execute();
    foreach ($roleNames as $r) {
      db_insert('webform_roles')->fields(array('nid' => $node->nid, 'rid' => $this->roleId($r)))->execute();
    }
  }

  /**
   * A proposal whose ballot is open to the given roles (names).
   */
  public function createProposal(array $roleNames, array $values = array()) {
    $webform = webform_node_defaults();
    $webform['roles'] = array();
    foreach ($roleNames as $r) {
      $webform['roles'][] = $this->roleId($r);
    }
    return $this->createNode('proposal', $values + array('webform' => $webform));
  }

  /**
   * A node of a webform-enabled type ($type), open to $roles.
   */
  public function createWebformNode($type, array $values, array $roles = array(DRUPAL_AUTHENTICATED_RID)) {
    $webform = webform_node_defaults();
    $webform['roles'] = $roles;
    return $this->createNode($type, $values + array('webform' => $webform));
  }

  public function createWebform(array $roles, array $components) {
    $webform = webform_node_defaults();
    $webform['roles'] = $roles;
    $webform['components'] = $components;
    return $this->createNode('webform', array('webform' => $webform));
  }

  // ---------------------------------------------------------------------------
  // Configuration without an admin form.
  // ---------------------------------------------------------------------------

  public function setConfig($name, $value) {
    variable_set($name, $value);
  }

  public function deleteConfig($name) {
    variable_del($name);
  }

  public function config($name, $default = NULL) {
    $this->resetCaches();
    return variable_get($name, $default);
  }

  // ---------------------------------------------------------------------------
  // External systems.
  // ---------------------------------------------------------------------------

  /**
   * The quiz engine reports that $account passed $quiz (quiz_rules event).
   *
   * @return string[]
   *   Messages the quiz taker would see.
   */
  public function passQuiz($account, $quiz) {
    db_insert('quiz_node_results')->fields(array(
      'nid' => $quiz->nid, 'vid' => node_load($quiz->nid)->vid, 'uid' => $account->uid,
      'time_start' => REQUEST_TIME - 60, 'time_end' => REQUEST_TIME, 'released' => 0, 'score' => 100, 'is_evaluated' => 1,
    ))->execute();
    drupal_get_messages();
    $this->runAs($account, function () use ($quiz, $account) {
      rules_invoke_event('quiz_rules_quiz_passed', node_load($quiz->nid), user_load($account->uid));
    });
    $messages = array();
    foreach (drupal_get_messages() as $list) {
      foreach ($list as $m) {
        $messages[] = strip_tags($m);
      }
    }
    $this->resetCaches();
    return $messages;
  }

  // ---------------------------------------------------------------------------
  // Time travel.
  // ---------------------------------------------------------------------------

  /**
   * Forgets that a once-a-day cron job ran today (vacation_cron, proposal_cron…).
   */
  public function resetDailyCron($variable) {
    variable_set($variable, 0);
  }

  public function markDailyCronRan($variable, $timestamp) {
    variable_set($variable, $timestamp);
  }

  public function backdateNode($node, $timestamp) {
    db_update('node')->fields(array('changed' => $timestamp))->condition('nid', $node->nid)->execute();
    db_update('node_revision')->fields(array('timestamp' => $timestamp))->condition('nid', $node->nid)->execute();
    $this->resetCaches();
  }

  /**
   * Moves the end of the user's current vacation.
   */
  public function setVacationEnd($account, $timestamp) {
    db_update('vacation')->fields(array('end' => $timestamp))->condition('uid', $account->uid)->execute();
  }

  /**
   * Records a past chat-room visit.
   */
  public function setRoomVisit($room, $account, $timestamp) {
    db_query('REPLACE INTO {rpg_chat_room_users} (nid, uid, timestamp) VALUES (:nid, :uid, :t)',
      array(':nid' => $room->nid, ':uid' => $account->uid, ':t' => $timestamp));
  }

  /**
   * Records past election wins (cycle numbers start at 1).
   */
  public function recordElectionWin($account, $positionKey, $cycle) {
    db_insert('rpg_election_winners')->fields(array('uid' => $account->uid, 'cycle' => $cycle, 'position' => $positionKey))->execute();
  }

  /**
   * Pushes a node's workflow history back a few seconds (see transition()).
   */
  public function ageWorkflowHistory($node) {
    db_query('UPDATE {workflow_node_history} SET stamp = stamp - 5 WHERE nid = :nid', array(':nid' => $node->nid));
  }

  /**
   * Expires cached Views results (several views cache for an hour per role set).
   */
  public function expireViewsCache() {
    cache_clear_all('*', 'cache_views_data', TRUE);
  }

  public function backdateNodeCreated($node, $timestamp) {
    db_update('node')->fields(array('created' => $timestamp))->condition('nid', $node->nid)->execute();
  }

  public function backdateComment($comment, $timestamp) {
    db_update('comment')->fields(array('created' => $timestamp, 'changed' => $timestamp))->condition('cid', $comment->cid)->execute();
  }

  /**
   * Deletes a file or directory under the public files directory.
   */
  public function deletePublicPath($relative) {
    $path = DRUPAL_ROOT . '/' . variable_get('file_public_path', 'sites/default/files') . '/' . $relative;
    if (is_dir($path)) {
      file_unmanaged_delete_recursive($path);
    }
    elseif (file_exists($path)) {
      unlink($path);
    }
  }

  /**
   * Public files path relative to the site root ("sites/default/files").
   */
  public function publicFilesPath() {
    return variable_get('file_public_path', 'sites/default/files');
  }

  /**
   * Moves a node to a workflow state as the superuser, firing the same workflow
   * hooks and rules as the Workflow tab. Used only where the tab can't: workflow
   * 7.x-1.2 renders a single possible move as a value element without #value,
   * so that move can't be submitted.
   */
  public function forceWorkflowState($node, $stateName) {
    $loaded = node_load($node->nid, NULL, TRUE);
    $map = workflow_get_workflow_type_map_by_type($loaded->type);
    $sid = db_query('SELECT sid FROM {workflow_states} WHERE wid = :wid AND state = :s AND status = 1',
      array(':wid' => $map->wid, ':s' => $stateName))->fetchField();
    if (!$sid) {
      throw new \InvalidArgumentException("No workflow state '$stateName' for {$loaded->type}");
    }
    $this->runAs(user_load(1), function () use ($loaded, $sid) {
      workflow_execute_transition($loaded, $sid, NULL, TRUE);
    });
    $this->resetCaches();
  }

  public function unblockAllIps() {
    db_delete('blocked_ips')->execute();
  }

  /**
   * Makes cron think it last ran at $timestamp (drives "day older" events).
   */
  public function setLastCron($timestamp) {
    variable_set('cron_last', $timestamp);
  }

  public function backdateAccountCreated($account, $timestamp) {
    db_update('users')->fields(array('created' => $timestamp))->condition('uid', $account->uid)->execute();
  }

  // ---------------------------------------------------------------------------
  // Internals.
  // ---------------------------------------------------------------------------

  /**
   * Converts a scalar field value into Drupal field items.
   */
  private function fieldItems($field, $value) {
    if (is_array($value) && isset($value[LANGUAGE_NONE])) {
      return $value;
    }
    $info = field_info_field($field);
    $column = 'value';
    if ($info) {
      $columns = array_keys($info['columns']);
      $column = reset($columns);
    }
    if ($value === array()) {
      return array(LANGUAGE_NONE => array());
    }
    $list = (is_array($value) && array_keys($value) === range(0, count($value) - 1)) ? $value : array($value);
    $items = array();
    foreach ($list as $v) {
      $items[] = is_array($v) ? $v : array($column => $v);
    }
    return array(LANGUAGE_NONE => $items);
  }

  public function runAs($account, callable $fn) {
    global $user;
    $saved = $user;
    drupal_save_session(FALSE);
    $user = user_load($account->uid);
    try {
      return $fn();
    }
    finally {
      $user = $saved;
      drupal_save_session(TRUE);
    }
  }

  public function resetCaches() {
    drupal_static_reset();
    $GLOBALS['conf'] = variable_initialize(array());
    foreach (array_keys(entity_get_info()) as $type) {
      entity_get_controller($type)->resetCache();
    }
  }

}
