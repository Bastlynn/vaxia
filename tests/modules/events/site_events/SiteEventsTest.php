<?php

namespace Vaxia\Tests\Events\SiteEvents;

use Vaxia\Tests\VaxiaTestCase;

/**
 * Site events: event nodes with a date ("When") and a scheduled/cancelled
 * workflow; players answer with an
 * Attendance comment. Once a day cron mails reminders to the organizer and to
 * everyone whose latest answer is "Yes!". The window it picks is the day after
 * tomorrow (see the knownbug). Calendar pages list scheduled events.
 *
 * @group site_events
 */
class SiteEventsTest extends VaxiaTestCase {

  /**
   * A published event at noon on the day $offset days from today.
   */
  private function event($offset, array $values = array(), $state = 'scheduled') {
    $start = strtotime(date('Y-m-d 12:00:00', strtotime($offset . ' days')));
    $event = $this->site->createNode('event', $values + array(
      'uid' => $this->site->createUser(array('player'))->uid,
      'title' => $this->site->uniqueName('Event'),
      'field_tag_realm' => $this->site->term('realm', 'Vaxia'),
      'field_when' => array(array('value' => gmdate('Y-m-d H:i:s', $start), 'value2' => gmdate('Y-m-d H:i:s', $start + 7200))),
    ));
    $this->transition($event, 'scheduled');
    if ($state !== 'scheduled') {
      $this->transition($event, $state);
    }
    return $event;
  }

  private function answer($account, $event, $attendance) {
    $this->login($account);
    $this->submitForm('node/' . $event->nid, array(
      'field_attendance[und]' => $attendance,
      'comment_body[und][0][value]' => 'Answer: ' . $attendance,
    ), 'Save');
  }

  private function remind() {
    $this->site->resetDailyCron('site_events_send_reminders');
    $this->runCron();
  }

  private function mailsTo($account, $subject) {
    return array_values(array_filter($this->sentMail(), function ($m) use ($account, $subject) {
      return $m['to'] === $account->mail && $m['subject'] === $subject;
    }));
  }

  public function testOrganizerIsReminded() {
    $event = $this->event(2);
    $this->remind();
    $mails = array_filter($this->sentMail(), function ($m) {
      return $m['subject'] === '[Vaxia] You have an event tomorrow';
    });
    $this->assertNotEmpty($mails);
    $this->assertStringContainsString($event->title, implode(' ', array_column($mails, 'body')));
  }

  public function testAttendeesWhoSaidYesAreReminded() {
    $event = $this->event(2);
    $yes = $this->site->createUser(array('player'));
    $no = $this->site->createUser(array('player'));
    $this->answer($yes, $event, 'yes');
    $this->answer($no, $event, 'no-time');

    $this->remind();

    $this->assertCount(1, $this->mailsTo($yes, '[Vaxia] You have an event scheduled for tomorrow'));
    $this->assertSame(array(), $this->mailsTo($no, '[Vaxia] You have an event scheduled for tomorrow'));
  }

  public function testOnlyTheLatestAnswerCounts() {
    $event = $this->event(2);
    $fickle = $this->site->createUser(array('player'));
    $this->answer($fickle, $event, 'yes');
    $this->answer($fickle, $event, 'no-interest');
    $this->assertStringContainsString('Answer: no-interest', $this->pageText(), 'Second answer posted');
    $this->remind();
    $this->assertSame(array(), $this->mailsTo($fickle, '[Vaxia] You have an event scheduled for tomorrow'));
  }

  public function testRemindersGoOutOncePerDay() {
    $event = $this->event(2);
    $yes = $this->site->createUser(array('player'));
    $this->answer($yes, $event, 'yes');
    $this->remind();
    $this->runCron();
    $this->assertCount(1, $this->mailsTo($yes, '[Vaxia] You have an event scheduled for tomorrow'));
  }

  public function testUnpublishedAndLaterEventsAreSkipped() {
    $hidden = $this->event(2, array('status' => 0));
    $later = $this->event(5);
    $yes = $this->site->createUser(array('player'));
    $this->answer($yes, $later, 'yes');
    $this->remind();
    $bodies = implode(' ', array_column($this->sentMail(), 'body'));
    $this->assertStringNotContainsString($hidden->title, $bodies);
    $this->assertStringNotContainsString($later->title, $bodies);
  }

  public function testEventListShowsUpcomingEvents() {
    $event = $this->event(3);
    $this->login($this->site->createUser(array('player')));
    $this->get('calendar/event-list');
    $this->assertStatus(200);
    $this->assertText($event->title);
  }

  public function testCalendarPagesLoad() {
    $this->login($this->site->createUser(array('player')));
    foreach (array('calendar', 'calendar/ical') as $path) {
      $this->get($path);
      $this->assertStatus(200, $path);
    }
  }

  /**
   * @group knownbug
   * The reminder query ignores the event's workflow state: cancelled events
   * still send reminders.
   */
  public function testCancelledEventsSendNoReminders() {
    $event = $this->event(2, array(), 'cancelled');
    $this->remind();
    $this->assertStringNotContainsString($event->title, implode(' ', array_column($this->sentMail(), 'body')));
  }

  /**
   * @group knownbug
   * Reminders say "tomorrow", but the window starts at strtotime('+2 day'):
   * cron reminds about the day after tomorrow, and an event tomorrow gets no
   * reminder that night.
   */
  public function testEventTomorrowIsReminded() {
    $event = $this->event(1);
    $this->remind();
    $bodies = implode(' ', array_column($this->sentMail(), 'body'));
    $this->assertStringContainsString($event->title, $bodies);
  }

}
