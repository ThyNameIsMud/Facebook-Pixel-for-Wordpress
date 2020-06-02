<?php
/*
 * Copyright (C) 2017-present, Facebook, Inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; version 2 of the License.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

namespace FacebookPixelPlugin\Tests\Integration;

use FacebookPixelPlugin\Integration\FacebookWordpressCalderaForm;
use FacebookPixelPlugin\Core\FacebookServerSideEvent;
use FacebookPixelPlugin\Tests\FacebookWordpressTestBase;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\UserData;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * All tests in this test class should be run in separate PHP process to
 * make sure tests are isolated.
 * Stop preserving global state from the parent process.
 */
final class FacebookWordpressCalderaFormTest extends FacebookWordpressTestBase {
  public function testInjectPixelCode() {
    \WP_Mock::expectActionAdded('caldera_forms_ajax_return',
      array(FacebookWordpressCalderaForm::class, 'injectLeadEvent'),
      10, 2);

    FacebookWordpressCalderaForm::injectPixelCode();
    $this->assertHooksAdded();

    $this->assertCount(0,
      FacebookServerSideEvent::getInstance()->getTrackedEvents());
  }

  public function testInjectLeadEventWithoutAdminAndSubmitted() {
    self::mockIsAdmin(false);
    self::mockUseS2S(false);
    $mock_out = array('status' => 'complete', 'html' => 'successful submitted');

    $out = FacebookWordpressCalderaForm::injectLeadEvent($mock_out, null);

    $this->assertArrayHasKey('html', $out);
    $code = $out['html'];
    $this->assertRegexp(
      '/caldera-forms[\s\S]+End Facebook Pixel Event Code/', $code);
  }

  public function testInjectLeadEventWithoutAdminAndNotSubmitted() {
    self::mockIsAdmin(false);
    self::mockUseS2S(false);
    $mock_out = array(
      'status' => 'preprocess',
      'html' => 'fail to submit form');
    $mock_form = array();

    $out = FacebookWordpressCalderaForm::injectLeadEvent($mock_out, $mock_form);

    $this->assertArrayHasKey('html', $out);
    $code = $out['html'];
    $this->assertEquals('fail to submit form', $code);

    $this->assertCount(0,
      FacebookServerSideEvent::getInstance()->getTrackedEvents());
  }

  public function testInjectLeadEventWithAdmin() {
    self::mockIsAdmin(true);
    self::mockUseS2S(false);
    $mock_out = array('status' => 'complete', 'html' => 'successful submitted');
    $mock_form = array();

    $out = FacebookWordpressCalderaForm::injectLeadEvent($mock_out, $mock_form);

    $this->assertArrayHasKey('html', $out);
    $code = $out['html'];
    $this->assertEquals('successful submitted', $code);

    $this->assertCount(0,
      FacebookServerSideEvent::getInstance()->getTrackedEvents());
  }

  public function testSendLeadEventViaServerAPISuccessWithoutAdmin() {
    self::mockIsAdmin(false);
    self::mockUseS2S(true);

    $mock_out = array('status' => 'complete', 'html' => 'successful submitted');
    $mock_form = self::createMockForm();
    $_SERVER['HTTP_REFERER'] = 'TEST_REFERER';

    $out = FacebookWordpressCalderaForm::injectLeadEvent($mock_out, $mock_form);

    $this->assertArrayHasKey('html', $out);
    $code = $out['html'];
    $this->assertRegexp(
      '/caldera-forms[\s\S]+End Facebook Pixel Event Code/', $code);

    $tracked_events =
      FacebookServerSideEvent::getInstance()->getTrackedEvents();

    $this->assertCount(1, $tracked_events);

    $event = $tracked_events[0];
    $this->assertEquals('Lead', $event->getEventName());
    $this->assertNotNull($event->getEventTime());
    $this->assertEquals('pika.chu@s2s.com', $event->getUserData()->getEmail());
    $this->assertEquals('Pika', $event->getUserData()->getFirstName());
    $this->assertEquals('Chu', $event->getUserData()->getLastName());
    $this->assertEquals('(206)123-4567', $event->getUserData()->getPhone());
    $this->assertEquals('caldera-forms',
      $event->getCustomData()->getCustomProperty('fb_integration_tracking'));
    $this->assertEquals('TEST_REFERER', $event->getEventSourceUrl());
  }

  public function testSendLeadEventViaServerAPIFailureWithoutAdmin() {
    self::mockIsAdmin(false);
    self::mockUseS2S(true);
    $mock_out = array(
      'status' => 'preprocess',
      'html' => 'fail to submit form');
    $mock_form = array();

    $out = FacebookWordpressCalderaForm::injectLeadEvent($mock_out, $mock_form);

    $this->assertArrayHasKey('html', $out);
    $code = $out['html'];
    $this->assertEquals('fail to submit form', $code);

    $this->assertCount(0,
      FacebookServerSideEvent::getInstance()->getTrackedEvents());
  }

  public function testSendLeadEventViaServerAPIFailureWithAdmin() {
    self::mockIsAdmin(true);
    self::mockUseS2S(true);
    $mock_out = array('status' => 'complete', 'html' => 'successful submitted');
    $mock_form = array();

    $s2s_spy = \Mockery::spy(FacebookServerSideEvent::class);

    $out = FacebookWordpressCalderaForm::injectLeadEvent($mock_out, $mock_form);

    $this->assertArrayHasKey('html', $out);
    $code = $out['html'];
    $this->assertEquals('successful submitted', $code);

    $this->assertCount(0,
      FacebookServerSideEvent::getInstance()->getTrackedEvents());
  }

  private static function createMockForm() {
    $email_field = array(
      'ID' => 'fld_1',
      'type' => 'email'
    );

    $first_name_field = array(
      'ID' => 'fld_2',
      'slug' => 'first_name'
    );

    $last_name_field = array(
      'ID' => 'fld_3',
      'slug' => 'last_name'
    );

    $phone = array(
      'ID' => 'fld_4',
      'type' => 'phone'
    );

    $_POST['fld_1'] = 'pika.chu@s2s.com';
    $_POST['fld_2'] = 'Pika';
    $_POST['fld_3'] = 'Chu';
    $_POST['fld_4'] = '(206)123-4567';

    return array(
      'fields' => array($email_field, $first_name_field, $last_name_field,
        $phone));
  }
}
