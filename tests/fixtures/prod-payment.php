<?php

/**
 * @file
 * Production payment setup (2017-07-10 backup): the PayPal method is payment
 * method 6 ("Vaxia Donations - Donation completed" checks pmid 6) and the
 * donation block is configured to use it, with the fund ("piggybank") text.
 *
 * Run with: drush scr /var/www/tests/fixtures/prod-payment.php
 */

$prod = array('payment_method_unavailable' => 4, 'paypal_vaxia_fund' => 6);
$tables = array('payment_method', 'paypal_payment_pps_payment_method', 'payment');
$txn = db_transaction();
foreach ($prod as $name => $pmid) {
  $current = db_query('SELECT pmid FROM {payment_method} WHERE name = :n', array(':n' => $name))->fetchField();
  if ($current && (int) $current !== $pmid) {
    foreach ($tables as $table) {
      db_update($table)->fields(array('pmid' => $pmid))->condition('pmid', $current)->execute();
    }
  }
}
unset($txn);

variable_set('payment_donation_pmid', 6);
variable_set('payment_donation_payment_currency_code', 'USD');
variable_set('payment_donation_payment_description', 'Vaxia Contribution to Maintain Server');
variable_set('payment_donation_block_body', array('value' => "<?php\nprint _vaxia_donation_piggybank();\n?>", 'format' => 'php_code'));
entity_get_controller('payment_method')->resetCache();
drush_print('Payment methods: ' . implode(', ', db_query("SELECT CONCAT(pmid, '=', name) FROM {payment_method}")->fetchCol()));
