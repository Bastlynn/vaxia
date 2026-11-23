<?php

namespace Vaxia\Tests\Ui\CommentRevisionsDiff;

use Vaxia\Tests\VaxiaTestCase;

/**
 * Comment revisions diff: edited chat posts keep their revisions
 * (comment_revisions); this module lists them on the post's Revisions tab and
 * shows a diff between two revisions. It's meant for staff with "view comment
 * revisions diffs" (or "administer nodes").
 *
 * @group comment_revisions_diff
 */
class CommentRevisionsDiffTest extends VaxiaTestCase {

  /**
   * A post, then edited by staff: two revisions.
   */
  private function editedPost($before = 'I attack the troll.', $after = 'I flee from the troll.') {
    $room = $this->site->createRoom();
    $post = $this->site->postComment($room, $this->site->createUser(array('player')), $before);
    $this->inSession($this->superuser(), function () use ($post, $after) {
      $this->submitForm('comment/' . $post->cid . '/edit', array('comment_body[und][0][value]' => $after), 'Post');
    });
    return $post;
  }

  private function staff() {
    return $this->site->createUser(array('storyteller'), array('view comment revisions diffs', 'view comment revisions', 'view rpg chat'));
  }

  public function testRevisionsTabListsEdits() {
    $post = $this->editedPost();
    $this->login($this->staff());
    $this->get('comment/' . $post->cid . '/revisions');
    $this->assertStatus(200);
    $this->assertGreaterThanOrEqual(2, $this->xpath()->query('//input[@name="old"]')->length, 'Two revisions to compare');
  }

  public function testCompareShowsBothVersions() {
    $post = $this->editedPost();
    $this->login($this->staff());
    $this->get('comment/' . $post->cid . '/revisions');
    $this->submitForm(NULL, array(), 'Compare');
    $this->assertStatus(200);
    $this->assertText('attack');
    $this->assertText('flee');
  }

  /**
   * @group knownbug
   * SECURITY: the access callback starts with a leftover "return TRUE;", so
   * anyone, anonymous visitors included, can read every chat post's edit
   * history (what was said before it was edited away).
   */
  public function testAnonymousCannotReadRevisions() {
    $post = $this->editedPost('My secret plan', 'Nothing to see');
    $this->anonymous();
    $this->get('comment/' . $post->cid . '/revisions');
    $this->assertStatus(403);
  }

  /**
   * @group knownbug
   * Same cause: players without the permission can read others' edits.
   */
  public function testPlayersCannotReadRevisions() {
    $post = $this->editedPost('My secret plan', 'Nothing to see');
    $this->withoutPermission('view comment revisions diffs', array('anonymous user', 'authenticated user', 'player'), function () use ($post) {
      $this->login($this->site->createUser(array('player')));
      $this->get('comment/' . $post->cid . '/revisions');
      $this->assertStatus(403);
    });
  }

}
