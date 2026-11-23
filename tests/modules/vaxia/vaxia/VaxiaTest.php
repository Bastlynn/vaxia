<?php

namespace Vaxia\Tests\Vaxia\Vaxia;

use Vaxia\Tests\DiceTrait;
use Vaxia\Tests\VaxiaTestCase;

/**
 * Vaxia: the site's core RPG behaviour (beyond the dice roller and character
 * sheet system, tested through the modules that use them).
 *
 * - Chat post form: in-character rooms require posting as a character (limbo
 *   rooms allow none); narrative mode needs permission and is off in limbo;
 *   the last character used is preselected, with its KXP / XP.
 * - Posting as a character in an IC room earns KXP by length; limbo posts and
 *   edits earn none.
 * - Post colours come from the post, the character or the player, cleaned of
 *   anything but letters and digits.
 * - Owners can't approve their own characters or items from the Workflow tab.
 *
 * @group vaxia
 */
class VaxiaTest extends VaxiaTestCase {

  use DiceTrait;

  private function postForm($player, $room) {
    $this->login($player);
    $this->get('node/' . $room->nid);
  }

  private function characterOptions() {
    $out = array();
    foreach ($this->xpath()->query('//select[@name="field_comment_character[und]"]//option') as $o) {
      $out[$o->getAttribute('value')] = trim($o->textContent);
    }
    return $out;
  }

  private function limbo() {
    $room = $this->site->createRoom(array('title' => $this->site->uniqueName('Limbo')));
    $this->site->setConfig('vaxia_limbo_room', array($room->nid => $room->nid));
    return $room;
  }

  // ---------------------------------------------------------------------------
  // Settings.
  // ---------------------------------------------------------------------------

  public function testSettingsPage() {
    $this->login($this->site->createUser(array(), array('admin vaxia settings')));
    $this->get('admin/vaxia/vaxia');
    $this->assertStatus(200);
    $this->assertSame(1, $this->xpath()->query('//select[@name="vaxia_limbo_room[]"]')->length);
  }

  public function testSettingsNeedPermission() {
    $this->login($this->site->createUser(array('lead')));
    $this->get('admin/vaxia/vaxia');
    $this->assertStatus(403);
  }

  // ---------------------------------------------------------------------------
  // Post form.
  // ---------------------------------------------------------------------------

  public function testIcRoomsRequireACharacter() {
    $player = $this->roller();
    $this->rollingCharacter($player);
    $this->postForm($player, $this->site->createRoom());
    $this->assertArrayNotHasKey('_none', $this->characterOptions());
    $this->assertSame(1, $this->xpath()->query('//input[@value="Post"]')->length >= 1 ? 1 : 0);
  }

  public function testLimboAllowsPostingWithoutACharacter() {
    $player = $this->roller();
    $this->rollingCharacter($player);
    $this->postForm($player, $this->limbo());
    $this->assertArrayHasKey('_none', $this->characterOptions());
  }

  public function testNarrativeModeNeedsPermission() {
    $room = $this->site->createRoom();
    $this->withoutPermission('post in narrative format', array('anonymous user', 'authenticated user', 'player', 'new account'), function () use ($room) {
      $this->postForm($this->roller(), $room);
      $this->assertSame(0, $this->xpath()->query('//input[@type="checkbox" and starts-with(@name, "field_comment_narrative")]')->length);
    });
    $this->postForm($this->roller(array('post in narrative format')), $room);
    $this->assertSame(1, $this->xpath()->query('//input[@type="checkbox" and starts-with(@name, "field_comment_narrative")]')->length);
  }

  public function testNoNarrativeModeInLimbo() {
    $this->postForm($this->roller(array('post in narrative format')), $this->limbo());
    $this->assertSame(0, $this->xpath()->query('//input[@type="checkbox" and starts-with(@name, "field_comment_narrative")]')->length);
  }

  public function testLastCharacterIsPreselectedWithItsXp() {
    $player = $this->roller();
    $first = $this->rollingCharacter($player);
    $second = $this->rollingCharacter($player);
    $room = $this->site->createRoom();
    $this->postRoll($player, $room, $second, array());

    $this->postForm($player, $room);

    $selected = $this->xpath()->query('//select[@name="field_comment_character[und]"]//option[@selected]')->item(0);
    $this->assertSame((string) $second->nid, $selected->getAttribute('value'));
    $this->assertMatchesRegularExpression('#[\d.]+ / 70 KXP, \d+ XP#', $this->pageText());
  }

  // ---------------------------------------------------------------------------
  // KXP.
  // ---------------------------------------------------------------------------

  private function kxpShown($player, $room) {
    $this->postForm($player, $room);
    preg_match('#([\d.]+) / 70 KXP#', $this->pageText(), $m);
    return isset($m[1]) ? (float) $m[1] : NULL;
  }

  public function testIcPostsEarnKxp() {
    $player = $this->roller();
    $character = $this->rollingCharacter($player);
    $room = $this->site->createRoom();
    $this->postRoll($player, $room, $character, array(), array(), str_repeat('The rain falls on the old road. ', 30));
    $this->assertGreaterThan(0, $this->kxpShown($player, $room));
  }

  public function testLimboPostsEarnNoKxp() {
    $player = $this->roller();
    $character = $this->rollingCharacter($player);
    $limbo = $this->limbo();
    $this->postRoll($player, $limbo, $character, array(), array(), str_repeat('Out of character chatter. ', 30));
    $this->assertEquals(0, $this->kxpShown($player, $limbo));
  }

  public function testEditingAPostEarnsNoKxp() {
    $player = $this->roller(array('edit own comments'));
    $character = $this->rollingCharacter($player);
    $room = $this->site->createRoom();
    $text = $this->site->uniqueName('post');
    $this->postRoll($player, $room, $character, array(), array(), $text);
    $anchor = $this->xpath()->query('//a[starts-with(@id, "comment-")]')->item(0);
    $cid = substr($anchor->getAttribute('id'), 8);
    $this->submitForm('comment/' . $cid . '/edit', array('comment_body[und][0][value]' => $text . ' edited'), 'Post');
    $this->assertContains('Editing an existing comment - no kxp will be awarded.', $this->messages());
  }

  // ---------------------------------------------------------------------------
  // Post display.
  // ---------------------------------------------------------------------------

  private function postSpanStyle($player, $room, $character, $text) {
    $this->postRoll($player, $room, $character, array(), array(), $text);
    $span = $this->xpath()->query('//span[contains(@style, "color:")][.//text()[contains(., "' . $text . '")]]')->item(0);
    $this->assertNotNull($span, 'Coloured post');
    return $span->getAttribute('style');
  }

  public function testPostColourComesFromTheCharacter() {
    $player = $this->roller();
    $character = $this->rollingCharacter($player, array('field_post_color' => 'teal'));
    $this->assertSame('color:teal', $this->postSpanStyle($player, $this->site->createRoom(), $character, $this->site->uniqueName('post')));
  }

  public function testHexColoursGetAHash() {
    $player = $this->roller();
    $character = $this->rollingCharacter($player, array('field_post_color' => '00ff99'));
    $this->assertSame('color:#00ff99', $this->postSpanStyle($player, $this->site->createRoom(), $character, $this->site->uniqueName('post')));
  }

  public function testColoursCannotInjectStyles() {
    $player = $this->roller();
    $character = $this->rollingCharacter($player, array('field_post_color' => 'red;x'));
    $this->assertSame('color:#redx', $this->postSpanStyle($player, $this->site->createRoom(), $character, $this->site->uniqueName('post')));
  }

  // ---------------------------------------------------------------------------
  // Owners and approval.
  // ---------------------------------------------------------------------------

  public function testOwnersCannotApproveTheirOwnCharacters() {
    $storyteller = $this->site->createUser(array('player', 'storyteller'));
    $character = $this->site->createCharacter($storyteller);
    $this->transition($character, 'draft', $storyteller);
    $this->transition($character, 'submitted', $storyteller);
    $this->login($storyteller);
    $this->get('node/' . $character->nid . '/workflow');
    $this->assertStringContainsString('You own this node, you cannot set it to approved.', implode(' ', $this->messages()));
    $this->assertSame(0, $this->xpath()->query('//label[normalize-space()="approved"]')->length);
  }

}
