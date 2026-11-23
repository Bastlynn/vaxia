# Known bugs found while writing tests

Not fixed. Each module bug has a `@group knownbug` test that asserts the intended
behaviour and fails today. Items marked "Untested" have no visible trigger and
come from reading the code. Run them with `tests/run-tests.sh --group knownbug`.
PHP notices/warnings seen on pages during a run are appended to
`tests/.build/php-errors.log`.

## Install / environment

1. **proposal: fresh install crashes.** `proposal_schema()` declares
   `'primary key' => array('eid')`, but there is no `eid` column. Production only
   works because `proposal_update_*` rebuilt the table with `(nid, uid)`.
   The harness patches it during install only
   (`fixtures/install-patches/01-proposal-primary-key.patch`).
2. **Rules and views hardcode production role ids** (`"user_add_role": {"30": "30"}`).
   On any other install they add, remove, or check the wrong roles, or crash
   (`Undefined offset: 30` on every new account). The harness renumbers roles to the
   production ids (`fixtures/prod-role-ids.php`, confirmed against a 2017
   production backup). 4, 7, 11, 14, 20 were already deleted by 2017 and are
   placeholders.
3. **Role 30 ("new account") isn't exported.** "Set new account role on new accounts"
   adds it, but no feature creates it.
4. **Rules hardcode production workflow state ids** (`"new_state": {"29": "29"}`).
   A fresh install numbers states differently, and imports the Character evaluation
   states twice. The harness collapses the duplicates and renumbers states
   (`fixtures/prod-workflow-state-ids.php`), keeping the active copy (the importer
   soft-deletes the old one to status 0). Ids are confirmed against a 2017
   production backup. The Character evaluation workflow comes from the
   character_sheet contrib module; production customised it (`inactive`,
   `xp_transferred`, `removed_from_game`) and VAXIA code checks those names, but
   no VAXIA code exports it. The harness recreates it from the backup.
5. **30 fields exist only in production.** Rules, views, and PHP use them, but no
   feature exports them: user fields (`field_took_*`, `field_became_*`,
   `field_powers_expire`, `field_banned_until`, `field_last_ip`, `field_latitude`,
   `field_donated`, `field_xp_over_time`, `field_storyhost_character`,
   `field_characters_submitted`, ...), `field_is_open_npc` on character sheets,
   `field_parent` on rooms, and comment fields. The harness creates them with
   the production types (`fixtures/prod-missing-fields.php`).
6. **Undeclared dependencies.** ban_players rules call `smart_ip_get_location()`.
   The `forums` vocabulary and `taxonomy_forums` fields (elections, proposals,
   scenes) belong to core `forum`. Views filter on workflow state, which needs
   `workflow_views`. Thirteen views and storyhost_duties query privatemsg's
   tables (production ran privatemsg 7.x-1.4). comment_revisions_diff needs
   comment_revisions and diff. No module lists these. Without forum, election settings
   can't be saved, and without workflow_views every state filter is silently
   dropped (e.g. removed art shows in the gallery).
7. **Webform content types aren't exported.** proposal, application, and
   storyhost_application only behave as webforms because production set
   `webform_node_types` by hand (`fixtures/prod-settings.php`).
8. **Contrib pins:** workflow is 7.x-1.2. VAXIA reads `$node->workflow` and
   `workflow_state_name`, which 1.1 never loads, so production ran 1.2 or later.
   1.2's features importer deletes every transition it creates
   (`fixtures/prod-workflow-transitions.php` recreates them). 1.2 also can't
   submit a single possible move from the Workflow tab (its value element has no
   `#value`), so tests make that move through the driver.
   webform must be 3.x: the code uses the 3.x `'mandatory'`
   key and `webform_node_types`. webform_rules must be 7.x-1.6 (1.7 assumes
   webform 4 and loses submitted values). quiz is pinned to production's
   7.x-4.0-rc3. Other contrib is the latest 7.x release; the 2017 backup shows
   production on older ones (rules 2.9, views 3.14, entity 1.8, bbcode 1.0,
   user_badges 3.2, views_timelinejs 1.0-beta1, ...), possibly upgraded since.
9. **Rules are tied to production node ids.** E.g. cold storage's "Returning player
  request access" only fires for webform nid 11863, and walkthrough quizzes are
  nids 9781/10344. A rebuilt or migrated site needs these mapped by hand. Tests
  recreate the node at that nid. Realm term ids are hardcoded too (725 Vaxia,
  726 Sirian, 1004 Storyhost in field defaults, views, rules, and
  vaxia.module); the harness creates those terms at those ids
  (`fixtures/prod-realm-terms.php`). `quiz_rules` no longer exists on drupal.org,
  so a stub provides its `quiz_rules_quiz_passed` event.
10. **Role permissions and workflow tab access aren't exported.** Features export
  almost no permissions, so a fresh install leaves every role nearly powerless
  (storytellers can't open a character's Workflow tab), and the character
  workflow's tab roles come from the contrib default. Production grants a lot,
  including to anonymous and authenticated users (`view rpg chat`,
  `search old archives`, `use item shop`...). The harness loads the production
  permissions and tab roles (`fixtures/prod-role-permissions.php`,
  `fixtures/prod-workflow-state-ids.php`); tests that check a permission gate
  revoke it for the test.

## ban_players

11. **Banned users stay logged in.** "Bans - Add IP for banned user" records and
  blocks the IP, then redirects to `user/logout`, but the login form's redirect
  wins. The session survives, and only the IP block stops them. (Inferred:
  from a different IP they'd still be logged in. Not tested.)
12. Untested (from reading): **"Bans - Set player last IP" never runs.** It
  listens for user_stats' `user_stats_ip_address_insert`, which this user_stats
  release never fires (the call is commented out). Only the on-login rule
  updates the last IP. There's no visible trigger, so there's no test.

## archives

13. **SECURITY: command injection in the archive search.** `_archives_search()`
  runs `grep ... "<search text>"` through `exec()` with only `addslashes()`
  applied. `$(...)` inside double quotes still runs, so anyone with "search old
  archives" can run shell commands as the web server. Verified with a harmless
  probe file. With no archive path configured it also greps the whole site
  directory.
- Untested (from reading): archive cron is disabled (the hook is renamed
  `tmp_disable_archives_cron`), so archiving only happens from the admin button.

## cold_storage

14. **"No account found" is never shown.** The returning-player lookup puts its
  "No account matching this email was found" message in the view's No results
  area, which views_rules never renders. Players only see "Done searching".

## department

15. **Saving department settings can give anonymous visitors the vote.** The
  role pickers have no "none" choice and default to the first role, "anonymous
  user". Save without picking a role for every department and anonymous users
  get `vote on proposal`.

16. **Election positions are never locked to departments.**
  `department_form__election_admin_settings_alter()` targets form id
  `_election_admin_settings`, but the form is now `_rpg_election_admin_settings`.
17. **Replacement picker warns, and preselects the wrong user.**
  `'#default_value' => array_unshift(array_keys(...))` returns a count, not a uid,
  and prints "Only variables should be passed by reference".
18. **"Vacation substitute cutoff" setting is ignored.** `department_vacation_cutoff`
  is saved but never read. The picker's visibility uses a hardcoded list (2 weeks
  and up). Client-side only, so there's no test.
19. **Changing a department's role never revokes voting.** Saving settings grants
  `vote on proposal` to the newly selected role; the previous role keeps it.

## items

20. **The item settings page crashes (HTTP 500).** `_items_admin_settings()` has
  `'#collapsed' => TRUE,s` twice. The stray `s` becomes a form element string and
  Drupal's form builder dies ("Unsupported operand types"). On PHP 8 the
  undefined constant alone is fatal. "Item approved" etc. can't be configured;
  the harness sets `items_approved_workflow` = 15 directly.
21. **The owner of a character can approve items attached to it.** items.module
  means to stop this, but its workflow-tab guard reads undefined `$node` and
  `$user` and never runs. (A general guard does stop an item's own author.)
22. **Intelligence overlap is never priced.** The field is
  `field_item_over_intellegence` (misspelled) but the value code reads
  `field_item_over_intelligence`.
- Untested (from reading): the oversight check builds percentage field names as
  `field_item_perc_field_item_<stat>`, so percentages are never checked. The
  power-value "base difficulty" reads the `items_at_will_value` setting (which
  also prices at-will powers).

## item_helper

23. **A craft + persist roll counts for nothing, silently.** The gate accepts two
  skills that are each craft/repair/persist, but the totals only count rolls
  where both are craft, or both are persist. A mixed roll (or one using
  "repair") gets no message and adds nothing.
24. **Difficulty 30 divides by zero; below 30 the totals are negative.** PP per
  point is `floor((1 + (diff - 40) / 10) * 25)`: 0 at difficulty 30 (a warning
  today, fatal on PHP 8) and negative below it. At the default 25 the scene
  shows "Max primary / secondary / skill : -3 / -2 / -4".

## item_shop

25. **Prices with decimals are mangled.** The affordability check and the
  purchase strip "." from the value ("12.50" becomes 1250), so such items are
  hidden as unaffordable or would be charged 100x.

## item_uses

26. **Item charges are never spent.** Charges are only counted in rooms where
  `_vaxia_is_ic_room()` is TRUE. Despite its name, that returns TRUE for the
  limbo (OOC) rooms (vaxia_chatrooms uses it as "is OOC"), and limbo rooms have
  no dice roller. Single-use and per-day items are unlimited.
27. **Per-day items never recharge.** The daily cron calls
  `_vaxia_get_in_workflows($dailyState)`, which puts the state in the node-types
  argument, so it finds no items.

## personal_rooms

28. **Personal rooms don't get the character's realm.** The "create personal room"
  rule copies `node:field-realm`, which doesn't exist (realm is
  `field_tag_realm`). That action fails (logged), the rest of the rule
  continues, and the room keeps the field's default realm.

## proposal

29. **Shadowbanned users can vote.** The checks call `shadowban_is_banned_user()`,
  which doesn't exist (vaxia_shadowban only ships rules and a role).
  `proposal_node_view()` also writes `$node->webform['status'] == FALSE;`, a
  comparison instead of an assignment.
30. **Launching prints a notice.** `_proposal_launch_form_submit()` fires
  `open_proposal` with an undefined `$node`, so the event gets nothing.
31. **Division by zero on almost every proposal page.** It happens whenever none of
  the eligible voters commented in the active window. Warning today, fatal on
  PHP 8.
32. **Proposals and Elections pages share `/proposals`.** One of the two page
  displays is unreachable.
- Untested (from reading): editing a proposal question resets its form key to
  `proposal_<uniqid>` (`$component` is undefined in the edit-form alter).
  `proposal_check_posted_in_last_timeframe()` is dead code with an SQL typo
  (`comment}`). The proposal list's "closes on" uses `created`, but proposals
  really close relative to `changed`.

## room_cleanup

33. **"Cleanup Rooms" never finishes once any room qualifies.** The loop runs
  `while (parents changed || rooms left to clean)`, so with anything to delete
  it spins forever (`set_time_limit(600)` resets each pass). Nothing gets
  deleted, and the same happens from cron when room cleanup cron is on.
  Verified: the request hangs.
34. **"Room cleanup last run" shows 01/01/1970.** It formats the on/off setting as a
  date; the real last run is saved as `room_cleanup_last_run` and never shown.
  With the setting off it's blank.
35. **"Make a Room" prints a notice.** The handler writes to an undefined `$room`
  ("Creating default object", fatal on PHP 8). Also copies `field_realm`, which
  doesn't exist. It redirects to the room's edit form, but users land on the
  room page.
- Untested (from reading): the timeframe default differs between the form
  ("12 months") and the cleanup itself ("6 months") until the form is saved.

## role_app

36. **Approval percentage can't be set.** The setting uses `'#type' => 'text'`,
  which isn't a Form API element, so it never renders. The 75% default is fixed.
37. **Division by zero with no approvers.** The application tally and the approval
  check divide by the approver count. That's a warning today and a fatal
  `DivisionByZeroError` on PHP 8.
38. **The overdue check does nothing.** Cron fires `role_app_is_overdue` for
  applications open over a week, but no rule listens for it. Not a crash, just
  dead code.

## rpg_rep

39. **Giving reputation prints a notice.** `_rpg_rep_add_submit()` logs with an
  undefined `$character`, so the log line has no character name.
- Untested here (date-gated): the monthly drift toward zero only runs when cron
  fires on the 1st of the month. The test skips on other days.

## rpg_chat

40. **SECURITY: chat posts leak through the refresh URL.** `system/rpg_chat/NID`
  has `access callback => TRUE` and never checks room access, so anyone,
  including anonymous visitors, can read any room's posts. That includes locked,
  mature, and personal rooms the normal page denies.
- Note: presence uses two windows. Loading a room prunes entries older than
  1 minute (`rpg_chat_cron()` runs on every node load), while the user list
  allows 15 minutes. In practice you're "in the room" for about a minute after
  your last refresh.

## rpg_election

41. **The yearly election never starts.** On election day, cron runs
  `DELETE FROM {users_roles} WHERE rid = :rid` with an extra `:uid` argument
  (from an undefined `$winner`), which throws a PDOException. `election_day` has
  already been set to today, so the election is skipped until next year. Only
  "Hold an election off-cycle" works.
42. **No election forum configured = no election.** The proposal is saved with
  forum tid `''`, which strict SQL rejects, so node_save fails.
43. **Opening and closing elections print PHP notices.** `_rpg_election_trigger_election()`
  assigns to an undefined `$node` ("Creating default object", fatal on PHP 8).
  The results code reads undefined indexes. `close_election` is invoked with an
  undefined `$wins`, and `$election->winners` keeps only the last position's
  winners.
- Unverified (from reading): `_rpg_election_get_winners()` `asort()`s the
  vote-count buckets instead of sorting by count descending, so when a candidate
  leads two positions, seat assignment order may not follow vote counts. The
  no-candidates branch also reads `$alphabet[$i]` with `$i` undefined.
44. **Warning on every user profile edit:** `Invalid argument supplied for foreach()`
   in `rpg_election_form_user_profile_form_alter()` (line 185). Users see it.

## scene_record

45. **The scene table has no column headings.** `theme('table')` gets `'headers'`
  instead of `'header'`.
46. **PHP notices.** The new-scene form reads `$scene` before it's set. Posts with
  no character (OOC) make the results code read `->title` and `->nid` of `FALSE`.
- Untested (from reading): the "scene ruler" form is empty (no fields), and it
  only shows to staff on submitted scenes. The ruling UI does nothing.

## silver

47. **Silver-removal message shows a raw placeholder:** "%add_silver silver has
  been removed" (the code passes `@add_silver`).
48. **Refused-investment message shows a raw placeholder:** "Unable to invest
  @invest" (the code passes `$invest`).

## strikes

49. **The Yellow strike confirmation omits the player's name.** Its message uses
  `[strike-user:name]`, a variable only the Red rule defines.

## suspend_players

50. **Suspensions never record a ban date.** check_reds' PHP field returns the new
  ban as `"12/22/2026"`, and Entity API rejects it for `field_banned_until` (it
  wants a timestamp). The rule logs the error and carries on: the player is
  blocked and stripped of roles, but `banned_until` stays empty, so it's
  effectively a permanent ban.
51. **Suspension message is blank.** "has been suspended for  months thru ." The
  `[new-ban-months]` and `[new-ban]` tokens render empty (the red count works).
  If they rendered, a first Red would say "0 months" (`([count]-1)*3`) while the
  ban is 1 month.
52. **The 2nd Yellow in a year is upgraded to Red, not the 3rd.** check_yellows
  drops counts below 2, and the count includes the strike just saved. Three
  Yellows produce two Reds.
53. **Suspensions never lift on their own (in this environment).** "Suspend -
  Unblock a suspended user" listens for user_stats_day_older, fired by cron.
  With the current Entity API the event fails: its variables are typed
  `'number'`, which isn't a Rules type, and I believe the argument handlers use
  the Rules 1 signature (inferred from the error, not traced). Production may
  run an older Entity API that tolerated this. Check whether suspensions ever
  lifted there.

## vacation

54. **"Return from vacation" is unreachable.** The fieldset only renders for users
   with a `can go on vacation` role, and for them the form alter ends any current
   vacation while building the page. A user on vacation (player + vacation mode)
   gets no form at all and must wait for cron.
55. **"longer (resign)" does nothing.** `_vacation_go_on_vacation()` uses an undefined
   `$account`, so no roles are removed and no vacation is recorded.
56. **Default duration is "3 days", not "1 week".** `'#default_option'` isn't a Form API key.
57. **Every "Go on vacation" prints a PHP warning.** `_vacation_create_vacation()` ends
   with `array_merge($form, …)` on an undefined `$form`.
58. **Missed cron = vacation never ends.** Cron only ends vacations whose end falls
    inside today. If cron skips that day, the user's roles are never returned.
59. Untested (from reading; no rule or page uses it): the Rules action "Put a
    user on vacation" (`vacation_rules_on()`) reads undefined `$account` and
    `$end`.
60. `_vacation_get_vacations_by_uid()` iterates a single `fetchObject()` result.
    It's unused, so there's no test.

## play_suggestions

61. **One click posts two suggestions (or none).** The validate handler calls the
  submit handler ("HACK"), then Drupal runs the submit handler again. The room
  pick is `rand(0, $count)` over `$count` rooms, so some picks find no room and
  post nothing. (Five clicks posted eight suggestions in a test run.)
- Untested (from reading): with no suggestions at all, `rand(0, -1)` gives
  `LIMIT -1, 1`, an SQL error when the button is pressed.

## top_tens

62. **Long XP types crash "Add XP".** Flavor types are free text in the settings,
  but `vaxia_xp_type_totals.type` is `varchar(15)`: awarding a longer type is a
  PDOException and the XP is lost.

## justice_form

63. **Rolls never reduce the crime's PP.** The PP a roll generates is read as
  `$ruling[7]`, but the dice ruler returns named keys (`'pp'`): every roll
  "generated  towards reducing the crime PP" and the full punishment is always
  due (plus an "Undefined offset: 7" notice per roll).
64. **"A good reputation raised the Tier level"** when it lowered it.
65. **The time punishment has no unit.** The tier's days/weeks/months setting is
  read but never printed ("OR 10 time.").

## storyhost_duties

66. **Late evaluations count as prompt.** The 3-day check compares
  `workflow_node_history.stamp` (Unix time) with
  `DATE_ADD(FROM_UNIXTIME(created), INTERVAL 3 DAY)` (a DATETIME, compared as
  20261123...): always true.
67. **Lapsed storyhosts are never retired.** "Remove SH roles if Neglect Duties"
  and "... if AWOL" listen for `user_stats_day_older` (broken under Rules 2, see
  suspend_players).
- "Replies on SH Requests" counts forum terms 10 and 11, production content ids.

## storyhost_renewal

68. **Storyhost powers never expire.** "SH Renewal - Expire Storyhost" listens
  for `user_stats_day_older` (see suspend_players).

## sh_test

69. **ASH 2 / ASH 3 eligibility warnings are inverted.** They check "not in the
  previous ASH role OR has run enough sessions", so an ASH who has run their
  sessions is told they're not eligible, and one who hasn't isn't warned.
70. **A session report with no characters prints a warning**
  (`sh_test_session_counts()` loops over an unset field) to anyone allowed to
  see whether it qualifies.
71. **"Session requirements" doesn't start collapsed:** `'#collpased'` (sic).
- Untested (from reading): `sh_test_check_last_run()` and
  `sh_test_check_date_last_run()` query a `{nodes}` table that doesn't exist
  (and the first lacks a space before ORDER BY); no exported rule calls them.
  `sh_test_check_ash_3_approvals()` divides by the number of possible approvers,
  which is zero when nobody holds an approving role. "Send Player Feedback on
  Session to Leads" (webform 9907) reads the storyhost's name from webform
  9832's submissions, a copy-paste of the other rule.

## site_events

72. **Reminders are for the day after tomorrow.** They say "tomorrow", but the
  window starts at `strtotime('+2 day')`; an event tomorrow gets no reminder
  that night.
73. **Cancelled events still send reminders.** The reminder query ignores the
  event's workflow state.

## comment_revisions_diff

74. **SECURITY: anyone can read every post's edit history.** The access callback
  starts with a leftover `return TRUE;`, so anonymous visitors and players can
  open `comment/N/revisions` and compare revisions: whatever was edited out of a
  post is public.

## dice_helper

75. **The dice proof page divides by zero with no d100 rolls yet** (a warning
  today, fatal on PHP 8).

## dice_ruler

76. **Every ruling prints undefined-index notices.** The rulings read "add diff"
  values (`two_trait_add_diff_a_N`, `combat_add_diff_*`, ...) the form never has.

## site_wiki

77. **Auto-links eat the next character.** The link replaces the whole match,
  including the character after the title: "to Rivermoot." becomes "to
  Rivermoot " (the period is lost).
- `site_wiki_menu_alter()` is written as a node-access hook (`$node, $op,
  $account`, returns NODE_ACCESS_DENY) but named as hook_menu_alter, so it never
  denies anything. Locked pages are still protected because their edit form is
  emptied.
- Untested (from reading): the freelinking overrides; the wiki text format has
  no freelinking filter.

## anon_mailer

78. **SECURITY (critical): anonymous remote code execution.** The "Grievance
  Posting" and "Grievance Posting - Incomplete" rules paste
  `[data:grievance_names-value]` (a free-text webform field) into `php_eval`
  code inside double quotes. The mailer (webform 9368) is open to anonymous
  users, so a name like `x"); <any PHP>; ("` runs on the server (confirmed in
  the test environment). Any rule putting submitted data into PHP code has
  the same problem.

## vaxia_evaluation

- Evaluator notifications ("A character is ready to be reviewed", "needs
  changes", workflow comments) go to roles 3 and 14. Role 14 no longer existed
  in 2017, so only administrators are mailed.

## vaxia_players

79. **New players' "XP over time" never starts.** "Add XP to total User XP" adds
  with data_calc, which refuses an empty input ("The variable or parameter
  input_1 is empty"). A player with no total yet never gets one, so SH
  eligibility (over 39) never triggers until an admin runs "Recalculate Earned
  XP" (sh_test).
80. **The underage mail's subject has no name.** It uses `[user:name]`, which a
  user_insert rule doesn't have: "Account details for  at Vaxia (NOT
  approved)".
81. **Inactive players are never retired.** "Retire inactive user account"
  listens for `user_stats_day_older` (see suspend_players).
82. **Accounts created as players also get "new account".** "Set new account
  role on new accounts" checks NOT (new account AND player); it means OR.
- The age and terms boxes are required on registration, so an under-13 sign-up
  is refused outright; the "User is under minimal age" rule only runs for
  accounts made another way.

## vaxia_rpg

83. **Blocked players get the cold storage mail twice.** The cold storage rule
  saves the account again, firing "user was blocked" a second time.
84. **The banned-IP login alert never fires.** The rule passes the IP to the
  view loop as PHP (`<?php echo ip_address(); ?>`), which isn't evaluated
  there; the view finds the banned player when given the real IP.

## vaxia_scene

85. **New scenes don't land on the Workflow tab.** "Creating scene move to
  workflow" redirects there, but vaxia_players' "Node redirect after
  creation" also lists scenes and redirects to node/N afterwards.

## vaxia_characters

86. **PHP notices on new character sheets.** Viewing a sheet still in
  "(creation)" reads `$node->workflow_state_name`, which it doesn't have; the
  edit form reads the owner's `field_xp_over_time['und'][0]` without checking
  it's set (every player without an XP total yet).

## vaxia_wiki

87. **Pages flagged for deletion never reach the review list.** Flagging
  unpublishes the page, and the "Flagged delete" list shows published pages
  only. (Any player may flag, so any player can unpublish any wiki page,
  with no list for staff to find it on.)

## vaxia (core)

- Untested (from reading): `_vaxia_comment_add_kxp_submit()` invokes
  `vaxia_gain_xp` with five arguments (player, character, room, amount, type)
  for an event declared with four, so the amount and type are shifted.
  `_vaxia_add_xp()` logs non-numeric XP with an undefined constant `type`
  (fatal on PHP 8); no form sends non-numeric XP.

## Found early, to pin when those modules get tests

- patched_modules/spoiler: `_spoiler_filter_process()` uses `create_function()`
  (deprecated notice on every post today; removed in PHP 8, so posts using the
  spoiler filter will fatal after the upgrade). Seen while testing select_image.
- vaxia: `_vaxia_get_sh_character()`-style code (vaxia.module:872–887) reads and
  writes `field_storyhost_character[...]['value']` and stores a node object there.
  The rules treat it as a node reference.
