<?php

/**
 * @file
 * Creates fields the modules use (rules, views, PHP) that no feature exports.
 * Production must have them (they were added through the UI), but a fresh install
 * from code doesn't, so rules touching them fail.
 *
 * Run with: drush scr /var/www/tests/fixtures/prod-missing-fields.php
 *
 * Names and types confirmed against a 2017-07-10 production backup
 * (`field_config`): the user dates are Date module "datetime" fields (ISO
 * strings, day granularity, no timezone handling).
 *
 * Deliberately NOT created:
 * - field_realm: only the personal-rooms rule reads it; the real realm field is
 *   field_tag_realm (see KNOWN-BUGS.md).
 * - field_agility, field_appearance, field_awareness, field_constitution,
 *   field_health: production "computed" fields (computed_field module, derived
 *   from the stats); only views show them. field_item_presence,
 *   field_item_reflexes: production number fields only views read.
 */

$fields = array(
  // User profile dates, set by rules with "now" / "+3 months".
  array('field_took_ash1', 'datetime', 'user', 'user', 'Took ASH 1'),
  array('field_took_ash2', 'datetime', 'user', 'user', 'Took ASH 2'),
  array('field_took_ash3', 'datetime', 'user', 'user', 'Took ASH 3'),
  array('field_took_eval_test', 'datetime', 'user', 'user', 'Took evaluator test'),
  array('field_took_setting_walkthrough', 'datetime', 'user', 'user', 'Took setting walkthrough'),
  array('field_took_social_walkthrough', 'datetime', 'user', 'user', 'Took social walkthrough'),
  array('field_took_system_walkthrough', 'datetime', 'user', 'user', 'Took system walkthrough'),
  array('field_became_evaluator', 'datetime', 'user', 'user', 'Became evaluator'),
  array('field_became_sh', 'datetime', 'user', 'user', 'Became storyhost'),
  array('field_powers_expire', 'datetime', 'user', 'user', 'Powers expire'),
  array('field_banned_until', 'datetime', 'user', 'user', 'Banned until'),
  // Other user profile fields.
  array('field_last_ip', 'text', 'user', 'user', 'Last IP'),
  // Multi-value: "Bans - Add IP for banned user" list_add()s to it.
  array('field_banned_ip', 'text', 'user', 'user', 'Banned IP', array('#cardinality' => FIELD_CARDINALITY_UNLIMITED)),
  array('field_reason_for_ban', 'text_long', 'user', 'user', 'Reason for ban'),
  // Text, not a number: rules fill them with PHP (smart_ip lookup), which Rules only evaluates for text.
  array('field_latitude', 'text', 'user', 'user', 'Latitude'),
  array('field_longitude', 'text', 'user', 'user', 'Longitude'),
  array('field_mature', 'list_boolean', 'user', 'user', 'Is 18 or older'),
  // On the registration form, required (unchecked saves 0: "under 13").
  array('field_age_requirement', 'list_boolean', 'user', 'user', 'Age requirement', array(
    'allowed_values' => array(0 => 'I am under 13 years old.', 1 => 'I am 13 years old or older.'),
    '#instance' => array('required' => 1, 'settings' => array('user_register_form' => 1), 'default_value' => array(array('value' => 0))),
  )),
  array('field_terms_and_conditions', 'list_boolean', 'user', 'user', 'Terms and Conditions', array(
    'allowed_values' => array(0 => 'I do NOT agree to the Terms and Conditions of Vaxia.org.', 1 => 'I agree to the Terms and Conditions of Vaxia.org.'),
    '#instance' => array('required' => 1, 'settings' => array('user_register_form' => 1), 'default_value' => array(array('value' => 0))),
  )),
  array('field_donated', 'number_integer', 'user', 'user', 'Donated'),
  array('field_xp_over_time', 'number_integer', 'user', 'user', 'XP over time'),
  array('field_characters_submitted', 'number_integer', 'user', 'user', 'Characters submitted'),
  array('field_characters_minmax', 'number_integer', 'user', 'user', 'Min-maxed characters'),
  // Node reference: rules walk into it (node:author:field-storyhost-character:field-titles).
  array('field_storyhost_character', 'node_reference', 'user', 'user', 'Storyhost character', array('referenceable_types' => array('character_sheet' => 'character_sheet'))),
  array('field_comment_color', 'text', 'user', 'user', 'Default post color'),
  // Character sheets.
  array('field_is_open_npc', 'list_boolean', 'node', 'character_sheet', 'Is open NPC'),
  // Chat rooms and chat posts (comments).
  array('field_parent', 'node_reference', 'node', 'rpg_chatroom', 'Parent room', array('referenceable_types' => array('rpg_chatroom' => 'rpg_chatroom'))),
  // Select lists fed by views: vaxia_form_alter() and the dice roller read the
  // grouped #options ("Characters", "Personal NPCs") these views produce.
  array('field_comment_character', 'node_reference', 'comment', 'comment_node_rpg_chatroom', 'Character', array(
    'referenceable_types' => array('character_sheet' => 'character_sheet'),
    'view' => array('view_name' => 'vaxia_character_sheets', 'display_name' => 'my_chars_ref', 'args' => array()),
    '#widget' => 'options_select',
  )),
  array('field_comment_color', 'text', 'comment', 'comment_node_rpg_chatroom', 'Post color'),
  array('field_artwork', 'node_reference', 'comment', 'comment_node_rpg_chatroom', 'Artwork', array(
    'referenceable_types' => array('artwork' => 'artwork'),
    'view' => array('view_name' => 'artwork', 'display_name' => 'artwork_mine_refs', 'args' => array()),
    // Production uses select_image's picker (thumbnails in the list).
    '#widget' => 'select_image_select',
  )),
  // Chat post fields production has on rooms' comments (2017 backup).
  array('field_comment_narrative', 'list_boolean', 'comment', 'comment_node_rpg_chatroom', 'As narrative'),
  array('field_ooc', 'text_long', 'comment', 'comment_node_rpg_chatroom', 'OOC'),
  // Copied from the character when posting (vaxia_comment_presave).
  array('field_description', 'text_long', 'comment', 'comment_node_rpg_chatroom', 'Description'),
  array('field_charisma', 'number_integer', 'comment', 'comment_node_rpg_chatroom', 'Charisma'),
  array('field_comment_presence', 'number_float', 'comment', 'comment_node_rpg_chatroom', 'Presence'),
  array('field_comment_appearance', 'number_float', 'comment', 'comment_node_rpg_chatroom', 'Appearance'),
  // Post background: per post, per player, per character (base field exported).
  array('field_background_color', 'list_text', 'comment', 'comment_node_rpg_chatroom', 'Background #'),
  array('field_background_color', 'list_text', 'user', 'user', 'Background #'),
  array('field_background_color', 'list_text', 'node', 'character_sheet', 'Default Post Background'),
  // Event RSVPs (comments on events).
  array('field_attendance', 'list_text', 'comment', 'comment_node_event', 'Attendance', array('allowed_values' => array(
    'yes' => 'Yes!',
    'idk' => "I don't know / uncertain",
    'waitlist' => 'Interested, but wait-list me for others',
    'no-interest' => 'Not attending',
    'no-time' => "Can't make it in this time slot",
  ))),
);

$widgets = array(
  'datestamp' => 'date_text',
  'datetime' => 'date_select',
  'text' => 'text_textfield',
  'text_long' => 'text_textarea',
  'number_float' => 'number',
  'number_decimal' => 'number',
  'number_integer' => 'number',
  'list_boolean' => 'options_onoff',
  'list_text' => 'options_select',
  'node_reference' => 'node_reference_autocomplete',
);

foreach ($fields as $def) {
  list($name, $type, $entity_type, $bundle, $label) = $def;
  $settings = isset($def[5]) ? $def[5] : array();
  $cardinality = isset($settings['#cardinality']) ? $settings['#cardinality'] : 1;
  $widget = isset($settings['#widget']) ? $settings['#widget'] : $widgets[$type];
  $instance_extra = isset($settings['#instance']) ? $settings['#instance'] : array();
  unset($settings['#cardinality'], $settings['#widget'], $settings['#instance']);
  if ($type === 'list_boolean') {
    $settings += array('allowed_values' => array(0 => '', 1 => ''));
  }
  if ($type === 'datetime') {
    $settings += array('granularity' => drupal_map_assoc(array('year', 'month', 'day')), 'tz_handling' => 'none', 'timezone_db' => '', 'todate' => '', 'repeat' => 0);
  }
  if (!field_info_field($name)) {
    field_create_field(array('field_name' => $name, 'type' => $type, 'cardinality' => $cardinality, 'settings' => $settings));
  }
  if (!field_info_instance($entity_type, $name, $bundle)) {
    field_create_instance($instance_extra + array(
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $label,
      'widget' => array('type' => $widget),
    ));
    drush_print("created $entity_type/$bundle $name ($type)");
  }
}
