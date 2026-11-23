<?php

namespace Vaxia\Tests\Vaxia\VaxiaDonation;

use Vaxia\Tests\VaxiaTestCase;

/**
 * Vaxia donation: the PayPal donation block (production: left sidebar). Players
 * pick one of their playable characters to receive donation XP, up to a yearly
 * cap; the form says when they have no character or have hit the cap. Admins
 * set the cap, the fund and the server cost.
 *
 * The XP itself is awarded when PayPal reports the payment complete (not
 * reachable here), by a rule tied to production's payment method 6.
 *
 * @group vaxia_donation
 */
class VaxiaDonationTest extends VaxiaTestCase {

  protected function setUp(): void {
    parent::setUp();
    $this->site->placeBlock('payment_donation', 'payment_donation', 'sidebar_first');
  }

  private function donor(array $values = array()) {
    return $this->site->createUser(array('player'), array('make vaxia donation'), $values);
  }

  private function donationForm($account) {
    $this->login($account);
    $this->get('node');
    return $this->pageText();
  }

  public function testSettingsDefaults() {
    $this->login($this->site->createUser(array(), array('administer vaxia donation')));
    $this->get('admin/vaxia/vaxia_donation');
    $this->assertStatus(200);
    $this->assertSame('100', $this->xpath()->query('//input[@name="vaxia_donation_yearly_cap"]')->item(0)->getAttribute('value'));
    $this->assertSame('40', $this->xpath()->query('//input[@name="vaxia_donation_site_cost"]')->item(0)->getAttribute('value'));
  }

  public function testSettingsNeedPermission() {
    $this->login($this->site->createUser(array('lead')));
    $this->get('admin/vaxia/vaxia_donation');
    $this->assertStatus(403);
  }

  public function testDonorsPickOneOfTheirPlayableCharacters() {
    $donor = $this->donor();
    $hero = $this->site->createCharacter($donor, array('title' => $this->site->uniqueName('Hero')));
    $this->approveCharacter($hero);
    $draft = $this->site->createCharacter($donor, array('title' => $this->site->uniqueName('Draft')));
    $npc = $this->site->createCharacter($donor, array('title' => $this->site->uniqueName('Npc'), 'field_is_npc' => 1));
    $this->approveCharacter($npc);

    $this->donationForm($donor);

    $options = array();
    foreach ($this->xpath()->query('//select[@name="donation_target"]/option') as $o) {
      $options[] = trim($o->textContent);
    }
    $this->assertSame(array($hero->title), $options);
    $this->assertText('Donation amount');
  }

  public function testDonorsWithoutCharactersAreTold() {
    $this->assertStringContainsString('You have no characters to select awards for.', $this->donationForm($this->donor()));
  }

  public function testDonorsAtTheCapAreThanked() {
    $donor = $this->donor(array('field_donated' => 100));
    $this->approveCharacter($this->site->createCharacter($donor));
    $text = $this->donationForm($donor);
    $this->assertStringContainsString('You have maxed out character rewards for the year. Thank you!', $text);
    $this->assertSame(0, $this->xpath()->query('//select[@name="donation_target"]')->length);
  }

  public function testCapIsConfigurable() {
    $this->inSession($this->superuser(), function () {
      $this->submitForm('admin/vaxia/vaxia_donation', array('vaxia_donation_yearly_cap' => '200'), 'Save configuration');
    });
    $donor = $this->donor(array('field_donated' => 100));
    $this->approveCharacter($this->site->createCharacter($donor));
    $this->donationForm($donor);
    $this->inSession($this->superuser(), function () {
      $this->submitForm('admin/vaxia/vaxia_donation', array('vaxia_donation_yearly_cap' => '100'), 'Save configuration');
    });
    $this->assertSame(1, $this->xpath()->query('//select[@name="donation_target"]')->length);
  }

  public function testBlockShowsTheFund() {
    $this->inSession($this->superuser(), function () {
      $this->submitForm('admin/vaxia/vaxia_donation', array('vaxia_donation_site_fund' => '140', 'vaxia_donation_site_cost' => '50'), 'Save configuration');
    });
    $text = $this->donationForm($this->donor());
    $this->assertStringContainsString('Estimated in account: $140', $text);
    $this->assertStringContainsString('Estimated months left: 2', $text);
  }

  public function testBlockAsksForTopUpWhenLow() {
    $this->inSession($this->superuser(), function () {
      $this->submitForm('admin/vaxia/vaxia_donation', array('vaxia_donation_site_fund' => '10', 'vaxia_donation_site_cost' => '50'), 'Save configuration');
    });
    $this->assertStringContainsString("Vaxia's donation account needs topping off.", $this->donationForm($this->donor()));
  }

}
