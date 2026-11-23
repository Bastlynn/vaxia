<?php

namespace Vaxia\Tests\Rpg\RpgChat;

use Vaxia\Tests\VaxiaTestCase;

/**
 * RPG chat: chat-enabled content types (RPG Chatroom) show their comments as a
 * live chat that refreshes over AJAX, with a list of who's in the room.
 *
 * @group rpg_chat
 */
class RpgChatTest extends VaxiaTestCase {

  /**
   * A player allowed into rooms. vaxia_chatrooms additionally requires
   * "view vaxia ic chat" for in-character rooms.
   */
  private function chatter(array $roles = array('player')) {
    return $this->site->createUser($roles, array('view rpg chat', 'view vaxia ic chat'));
  }

  private function userList() {
    $names = array();
    foreach ($this->xpath()->query('//div[@id="rpg-chat-userlist"]//li') as $li) {
      $names[] = trim($li->textContent);
    }
    return $names;
  }

  private function refresh($room) {
    $this->get('system/rpg_chat/' . $room->nid);
    return json_decode($this->content, TRUE);
  }

  private function setPageChat($on) {
    $this->inSession($this->superuser(), function () use ($on) {
      $this->submitForm('admin/structure/types/manage/page', array('rpg_chat' => $on), 'Save content type');
    });
  }

  // ---------------------------------------------------------------------------
  // Setup and access.
  // ---------------------------------------------------------------------------

  public function testChatSwitchOnContentTypeGatesAccess() {
    $this->withoutPermission('view rpg chat', array('anonymous user', 'authenticated user', 'player'), function () {
      $page = $this->site->createNode('page');
      $player = $this->site->createUser(array('player'));
      $this->setPageChat(TRUE);
      try {
        $this->login($player);
        $this->get('node/' . $page->nid);
        $this->assertStatus(403, 'Chat-enabled content needs "view rpg chat"');
      }
      finally {
        $this->setPageChat(FALSE);
      }
      $this->get('node/' . $page->nid);
      $this->assertStatus(200);
    });
  }

  public function testRoomsNeedViewRpgChat() {
    $this->withoutPermission('view rpg chat', array('anonymous user', 'authenticated user', 'player'), function () {
      $room = $this->site->createRoom();
      $this->login($this->site->createUser(array('player')));
      $this->get('node/' . $room->nid);
      $this->assertStatus(403);

      $this->login($this->chatter());
      $this->get('node/' . $room->nid);
      $this->assertStatus(200);
    });
  }

  public function testAnonymousCannotOpenRooms() {
    $this->withoutPermission('view rpg chat', array('anonymous user', 'authenticated user', 'player'), function () {
      $room = $this->site->createRoom();
      $this->anonymous();
      $this->get('node/' . $room->nid);
      $this->assertStatus(403);
    });
  }

  public function testChatAdminsCanOpenRooms() {
    $room = $this->site->createRoom();
    $this->login($this->site->createUser(array(), array('administer rpg chat', 'view vaxia ic chat')));
    $this->get('node/' . $room->nid);
    $this->assertStatus(200);
  }

  public function testOtherContentIsUnaffected() {
    $page = $this->site->createNode('page');
    $this->login($this->site->createUser(array('player')));
    $this->get('node/' . $page->nid);
    $this->assertStatus(200);
  }

  // ---------------------------------------------------------------------------
  // Room page.
  // ---------------------------------------------------------------------------

  public function testRoomPageShowsChatWithUserListAndRefresh() {
    $room = $this->site->createRoom();
    $this->site->postComment($room, $this->chatter(), 'Hello from the tavern');
    $this->login($this->chatter());
    $this->get('node/' . $room->nid);

    $xpath = $this->xpath();
    $this->assertSame(1, $xpath->query('//div[@id="rpg-chat-wrapper"]//div[@id="rpg-chat"]')->length);
    $this->assertText('Hello from the tavern');
    $this->assertText('Users in this room');
    $this->assertGreaterThan(0, $xpath->query('//form[contains(@id, "comment-form")]//input[@value="Refresh"]')->length, 'Refresh button on the post form');
    $this->assertStringContainsString('rpg_chat/js/rpg_chat.js', $this->content);
    $this->assertStringContainsString('"refreshRate":60', $this->content);
  }

  public function testPresentUsersAreListed() {
    $room = $this->site->createRoom();
    $other = $this->chatter();
    $this->login($other);
    $this->get('node/' . $room->nid);
    $this->login($this->chatter());
    $this->get('node/' . $room->nid);
    $this->assertContains($other->name, $this->userList());
  }

  /**
   * Current behaviour, intent unconfirmed: loading a room prunes visits older
   * than a minute, though the list itself allows 15.
   */
  public function testVisitorsDropOffAfterAMinute() {
    $room = $this->site->createRoom();
    $gone = $this->chatter();
    $this->site->setRoomVisit($room, $gone, strtotime('-2 minutes'));
    $this->login($this->chatter());
    $this->get('node/' . $room->nid);
    $this->assertNotContains($gone->name, $this->userList());
  }

  // ---------------------------------------------------------------------------
  // Refresh endpoint.
  // ---------------------------------------------------------------------------

  public function testRefreshReturnsChatAsAjaxInsert() {
    $room = $this->site->createRoom();
    $this->site->postComment($room, $this->chatter(), 'Latest line');
    $me = $this->chatter();
    $this->login($me);

    $commands = $this->refresh($room);

    $this->assertCount(1, $commands);
    $this->assertSame('insert', $commands[0]['command']);
    $this->assertStringContainsString('id="rpg-chat-wrapper"', $commands[0]['data']);
    $this->assertStringContainsString('Latest line', $commands[0]['data']);
    $this->assertStringContainsString($me->name, $commands[0]['data'], 'You are in the user list');
  }

  public function testRefreshMarksYouPresentForOthers() {
    $room = $this->site->createRoom();
    $me = $this->chatter();
    $this->login($me);
    $this->refresh($room);
    $this->login($this->chatter());
    $this->get('node/' . $room->nid);
    $this->assertContains($me->name, $this->userList());
  }

  public function testRefreshOfMissingRoomReturnsNoChat() {
    $this->login($this->chatter());
    $this->get('system/rpg_chat/999999');
    $this->assertNull(json_decode($this->content, TRUE));
    $this->assertStringNotContainsString('rpg-chat-wrapper', $this->content);
  }

  /**
   * @group knownbug
   * system/rpg_chat/NID has "access callback" TRUE and never checks room
   * access: anyone (even anonymous) can read any room's posts through it.
   */
  public function testRefreshRespectsRoomAccess() {
    $room = $this->site->createRoom();
    $this->site->postComment($room, $this->chatter(), 'Secret plans');
    $this->anonymous();
    $this->get('system/rpg_chat/' . $room->nid);
    $this->assertStringNotContainsString('Secret plans', $this->content);
  }

}
