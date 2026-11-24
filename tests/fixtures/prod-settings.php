<?php

/**
 * @file
 * Site settings (variables) production has but no feature exports.
 *
 * Run with: drush scr /var/www/tests/fixtures/prod-settings.php
 */

// Content types that are webforms. proposal (rpg/proposal), application
// (rpg/role_app) and storyhost_application (storyhosts/sh_test) all read
// $node->webform; without this, webform never saves or loads their settings.
variable_set('webform_node_types', array('webform', 'proposal', 'application', 'storyhost_application'));

// RPG chat is switched on per content type in the UI ("Enable RPG chat?" on the
// RPG Chatroom type). README: chatrooms use RPG chat.
variable_set('rpg_chat_rpg_chatroom', 1);

// README: "Character sheets should use the Vaxia system. Playable states should
// be set to Character evaluation:approved." (character_sheet contrib settings).
variable_set('character_sheet_systems', array('vaxia' => 'vaxia'));
variable_set('character_sheet_types', array('character_sheet' => 'vaxia'));
variable_set('character_sheet_vaxia_playable', array(4 => 4));

// README: "RPG Chatrooms should have Dice enabled, and use the Vaxia dice form."
variable_set('dice_roller_rpg_chatroom', 1);
variable_set('dice_roller_place_rpg_chatroom', 'both');
variable_set('dice_roller_form_rpg_chatroom', 'vaxia');

// Item settings. Its admin page (admin/vaxia/items) crashes ("TRUE,s" typo),
// so production must have saved this before the typo: approved items = 15.
variable_set('items_approved_workflow', 15);

// Account settings (2017 backup): visitors register themselves, with email
// verification; which profile fields others may see, and which the owner may
// not edit (vaxia_players).
variable_set('user_register', 1);
variable_set('user_email_verification', 1);
variable_set('vaxia_public_user_fields', "field_artwork_image\nfield_mature\ntimezone\nfield_message_contact\nprivatemsg\naccount\nfield_staff_setting_lead\nfield_staff_social_lead\nfield_staff_system_lead\nbadges\nfield_age_requirement\nfield_availability\n");
variable_set('vaxia_disabled_user_fields', "field_powers_expire\nfield_took_system_walkthrough\nfield_took_setting_walkthrough\nfield_took_social_walkthrough\nfield_xp_over_time\nfield_donated\nfield_terms_and_conditions\nfield_age_requirement\nfield_personal_folder\nfield_logged_in_today\nfield_storyhost_character\nfield_took_ash1\nfield_took_ash2\nfield_took_ash3\nfield_took_eval_test\nfield_ash_3_answers\nfield_ash_3_application\nfield_became_sh\nfield_became_evaluator\nfield_characters_submitted\nfield_characters_minmax\nfield_banned_until\nfield_last_ip\nfield_latitude\nfield_longitude\nfield_account_created\nfield_reason_for_ban\nfield_banned_ip\nfield_sh_private_notes\n");


// Character sheet settings (2017 backup): stat / skill ranges and min-max
// warnings, the XP over time needed for "Other" races and conditions, and
// which character fields people other than the owner and staff may see.
foreach (array(
  'vaxia_other_min_hxp' => '40',
  'vaxia_pc_skill_ave_warn' => '20',
  'vaxia_pc_skill_max' => '40',
  'vaxia_pc_skill_min' => '5',
  'vaxia_pc_skill_warn' => '10',
  'vaxia_pc_stat_high_cap' => '4',
  'vaxia_pc_stat_high_warn' => '3',
  'vaxia_pc_stat_max' => '40',
  'vaxia_pc_stat_min' => '5',
  'vaxia_pc_stat_min_warn' => '2',
  'vaxia_pc_stat_spend_cap' => '225',
  'vaxia_pc_stat_spend_warn' => '210',
  'vaxia_pc_stat_zero_cap' => '1',
) as $name => $value) {
  variable_set($name, $value);
}
variable_set('vaxia_public_char_fields', "field_description\nfield_age_app\nfield_sex\nfield_race\nfield_sirian_species\nfield_vaxia_species\nfield_other_species\nfield_appearance\nfield_charisma\nfield_presence\nfield_public_information\nfield_sh_public_information\nfield_achivements\nfield_personal_room");

// XP settings (2017 backup): KXP per post and conversion, and HXP transfers
// (75% of spent XP, no cap, the old sheet goes to xp_transferred = 23).
foreach (array(
  'vaxia_display_dice' => '6',
  'vaxia_kxp_per_words' => '20',
  'vaxia_kxp_ratio' => '70',
  'vaxia_max_kxp_post' => '10',
  'vaxia_transfer_cap' => '300',
  'vaxia_transfer_cap_on' => 0,
  'vaxia_transfer_percentage' => '0.75',
  'vaxia_transfer_workflow' => '23',
  // New sheets: 300 points, checked while in draft (state 2) with no XP.
  'vaxia_starting_points' => '300',
  'vaxia_starting_econ' => '25',
  'vaxia_draft_workflow' => '2',
) as $name => $value) {
  variable_set($name, $value);
}
