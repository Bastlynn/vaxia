<?php

namespace Vaxia\Tests\Vaxia\VaxiaChatrooms;

use Vaxia\Tests\VaxiaTestCase;

/**
 * Vaxia chatrooms: who may open a chat room. Mature rooms are for players
 * marked 18+; locked rooms for the users listed on them; in-character rooms
 * need "view vaxia ic chat" (limbo / OOC rooms don't). Anyone turned away is
 * sent to the front page with a reason. "bypass vaxia ic chat" skips all of
 * it. Rooms are marked IC or OOC for the chat styling.
 *
 * @group vaxia_chatrooms
 */
class VaxiaChatroomsTest extends VaxiaTestCase {

  private function chatter(array $values = array(), array $permissions = array()) {
    return $this->site->createUser(array('player'), array_merge(array('view rpg chat', 'view vaxia ic chat'), $permissions), $values);
  }

  /**
   * Opens the room; returns array(landed on the room?, messages).
   */
  private function open($account, $room) {
    $account ? $this->login($account) : $this->anonymous();
    $this->get('node/' . $room->nid);
    return array((bool) preg_match('#/node/' . $room->nid . '$#', $this->url), implode(' ', $this->messages()));
  }

  public function testMatureRoomsTurnAwayUnderagePlayers() {
    $room = $this->site->createRoom(array('field_mature_posting' => 1));
    list($in, $messages) = $this->open($this->chatter(array('field_mature' => 0)), $room);
    $this->assertFalse($in);
    $this->assertStringContainsString('You are not allowed to view this room. You are underage.', $messages);
  }

  public function testMatureRoomsAdmitAdults() {
    $room = $this->site->createRoom(array('field_mature_posting' => 1));
    list($in) = $this->open($this->chatter(array('field_mature' => 1)), $room);
    $this->assertTrue($in);
  }

  public function testLockedRoomsAdmitOnlyListedUsers() {
    $listed = $this->chatter();
    $room = $this->site->createRoom(array('field_locked_to_users' => 1, 'field_users' => array(array('uid' => $listed->uid))));

    list($in) = $this->open($listed, $room);
    $this->assertTrue($in, 'Listed user admitted');

    list($in, $messages) = $this->open($this->chatter(), $room);
    $this->assertFalse($in);
    $this->assertStringContainsString('You are not allowed to view this room. This room is locked.', $messages);
  }

  public function testBypassPermissionSkipsTheChecks() {
    $room = $this->site->createRoom(array('field_mature_posting' => 1, 'field_locked_to_users' => 1));
    list($in) = $this->open($this->chatter(array('field_mature' => 0), array('bypass vaxia ic chat')), $room);
    $this->assertTrue($in);
  }

  public function testIcRoomsNeedIcChatPermission() {
    $room = $this->site->createRoom();
    $this->withoutPermission('view vaxia ic chat', array('anonymous user', 'authenticated user', 'player', 'new account'), function () use ($room) {
      $account = $this->site->createUser(array('player'), array('view rpg chat'));
      list($in, $messages) = $this->open($account, $room);
      $this->assertFalse($in);
      $this->assertStringContainsString('This room is locked to anonymous users.', $messages);
    });
  }

  public function testLimboRoomsDoNotNeedIcChatPermission() {
    $room = $this->site->createRoom();
    $this->site->setConfig('vaxia_limbo_room', array($room->nid => $room->nid));
    $this->withoutPermission('view vaxia ic chat', array('anonymous user', 'authenticated user', 'player', 'new account'), function () use ($room) {
      list($in) = $this->open($this->site->createUser(array('player'), array('view rpg chat')), $room);
      $this->assertTrue($in);
    });
  }

  public function testAnonymousVisitorsAreKeptOutOfMatureAndLockedRooms() {
    foreach (array(array('field_mature_posting' => 1), array('field_locked_to_users' => 1)) as $values) {
      list($in) = $this->open(NULL, $this->site->createRoom($values));
      $this->assertFalse($in);
    }
  }

  public function testRoomsAreMarkedIcOrOoc() {
    $ic = $this->site->createRoom();
    $limbo = $this->site->createRoom();
    $this->site->setConfig('vaxia_limbo_room', array($limbo->nid => $limbo->nid));
    $account = $this->chatter(array(), array('post in narrative format'));
    $this->open($account, $ic);
    $this->assertSame(1, $this->xpath()->query('//*[contains(concat(" ", @class, " "), " ic-room ")]')->length);
    $this->assertRaw('vaxia_chatrooms/js/vaxia_chatrooms.js');
    $this->open($account, $limbo);
    $this->assertSame(1, $this->xpath()->query('//*[contains(concat(" ", @class, " "), " ooc-room ")]')->length);
  }

}
