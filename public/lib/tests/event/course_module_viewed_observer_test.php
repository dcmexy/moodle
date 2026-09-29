<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core\event;

global $CFG;

use advanced_testcase;
use context_course;
use context_module;
use core_tests\event\testable_observer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/externallib.php');
require_once($CFG->dirroot . '/mod/page/classes/external.php');
require_once($CFG->dirroot . '/mod/book/classes/external.php');
require_once(__DIR__ . '/../fixtures/event_fixtures.php');
require_once(__DIR__ . '/../fixtures/course_module_viewed_observer.php');

/**
 * Tests for course access observer updates.
 *
 * @package     core
 * @category    test
 * @copyright   2026 Catalyst IT Canada LTD
 * @author      Dean Chimezie <deanchimezie@catalyst-ca.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
final class course_module_viewed_observer_test extends advanced_testcase {
    /**
     * Create a participating course-view event.
     *
     * @param int $courseid The course id.
     * @param int $userid The event user id.
     * @return \core\event\course_viewed
     */
    private function create_course_view_event(int $courseid, int $userid): \core\event\course_viewed {
        return \core\event\course_viewed::create([
            'context' => context_course::instance($courseid),
            'userid' => $userid,
        ]);
    }

    /**
     * Create a non-participating test wrapper course module viewed event.
     *
     * @param int $courseid The course id.
     * @param int $cmid The course module id.
     * @param int $objectid The module instance id.
     * @param int $userid The event user id.
     * @return \core_tests\event\course_module_viewed
     */
    private function create_nonparticipating_wrapper_view_event(
        int $courseid,
        int $cmid,
        int $objectid,
        int $userid,
    ): \core_tests\event\course_module_viewed {
        $context = context_module::instance($cmid);

        return \core_tests\event\course_module_viewed::create([
            'context' => $context,
            'courseid' => $courseid,
            'objectid' => $objectid,
            'userid' => $userid,
        ]);
    }

    /**
     * Create a course and a page module.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass}
     */
    private function create_course_with_page(): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $page, $cm];
    }

    /**
     * Create a course with a book and chapter.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass}
     */
    private function create_course_with_book(): array {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $bookgenerator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $bookgenerator->create_chapter(['bookid' => $book->id]);

        return [$course, $book, $chapter];
    }

    /**
     * Test the production observer is registered for all events.
     */
    public function test_observer_is_registered(): void {
        $this->resetAfterTest();

        manager::phpunit_reset();
        $observers = manager::get_all_observers();

        $this->assertArrayHasKey('\core\event\base', $observers);
        $callbacks = array_map(
            static fn(\stdClass $observer): mixed => $observer->callable,
            $observers['\core\event\base'],
        );
        $this->assertContains('\core\event\observer::observe_course_access_event', $callbacks);
    }

    /**
     * Test that guest participating course events do not update access.
     */
    public function test_observer_ignores_guest_user(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $this->setGuestUser();

        $event = $this->create_course_view_event($course->id, $USER->id);
        testable_observer::observe_course_access_event($event);

        $this->assertFalse($DB->record_exists('user_lastaccess', [
            'userid' => $USER->id,
            'courseid' => $course->id,
        ]));
    }

    /**
     * Test that unauthenticated participating course events do not update access.
     */
    public function test_observer_ignores_unauthenticated_user(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $this->setUser();

        $event = $this->create_course_view_event($course->id, $USER->id);
        testable_observer::observe_course_access_event($event);

        $this->assertFalse($DB->record_exists('user_lastaccess', [
            'userid' => $USER->id,
            'courseid' => $course->id,
        ]));
    }

    /**
     * Test that access is created when update logic processes a participating course event for the acting user.
     */
    public function test_observer_creates_access(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $event = $this->create_course_view_event($course->id, $user->id);

        $this->assertFalse($DB->record_exists('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]));

        testable_observer::observe_course_access_event($event);

        $timeaccess = $DB->get_field('user_lastaccess', 'timeaccess', ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertIsNumeric($timeaccess);
        $this->assertGreaterThan(0, $timeaccess);
    }

    /**
     * Test that WS observer updates do not modify site lastaccess or last IP.
     */
    public function test_observer_does_not_update_lastip_or_site_access(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $oldlastaccess = time() - LASTACCESS_UPDATE_SECS - 10;
        $oldlastip = '10.0.0.1';

        $DB->update_record('user', (object) [
            'id' => $user->id,
            'lastaccess' => $oldlastaccess,
            'lastip' => $oldlastip,
        ]);

        $USER->lastaccess = $oldlastaccess;
        $USER->lastip = $oldlastip;
        $USER->currentcourseaccess = [];

        $event = $this->create_course_view_event($course->id, $user->id);
        testable_observer::observe_course_access_event($event);

        $updateduser = $DB->get_record('user', ['id' => $user->id], 'id, lastaccess, lastip', MUST_EXIST);
        $timeaccess = $DB->get_field('user_lastaccess', 'timeaccess', ['userid' => $user->id, 'courseid' => $course->id]);

        $this->assertSame($oldlastaccess, (int) $updateduser->lastaccess);
        $this->assertSame($oldlastip, $updateduser->lastip);
        $this->assertIsNumeric($timeaccess);
        $this->assertGreaterThan(0, $timeaccess);
    }

    /**
     * Test that sufficiently old access data is updated.
     */
    public function test_observer_updates_old_access(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $oldtime = time() - LASTACCESS_UPDATE_SECS - 10;
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id,
            'courseid' => $course->id,
            'timeaccess' => $oldtime,
        ]);

        $USER->currentcourseaccess = [];
        $event = $this->create_course_view_event($course->id, $user->id);
        testable_observer::observe_course_access_event($event);

        $newtime = $DB->get_field('user_lastaccess', 'timeaccess', ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertGreaterThan($oldtime, $newtime);
    }

    /**
     * Test that recent access remains throttled.
     */
    public function test_observer_throttles_recent_access(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $recenttime = time() - 5;
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id,
            'courseid' => $course->id,
            'timeaccess' => $recenttime,
        ]);

        $USER->currentcourseaccess = [];
        $event = $this->create_course_view_event($course->id, $user->id);
        testable_observer::observe_course_access_event($event);

        $timeaccess = $DB->get_field('user_lastaccess', 'timeaccess', ['userid' => $user->id, 'courseid' => $course->id]);
        $this->assertEquals($recenttime, $timeaccess);
    }

    /**
     * Test that a different event user does not update the acting user's access.
     */
    public function test_observer_ignores_different_event_user(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $currentuser = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();
        $this->setUser($currentuser);

        $event = $this->create_course_view_event($course->id, $otheruser->id);
        testable_observer::observe_course_access_event($event);

        $this->assertFalse($DB->record_exists('user_lastaccess', ['userid' => $currentuser->id, 'courseid' => $course->id]));
    }

    /**
     * Test that non-WS participating course events do not update access through the observer callback.
     */
    public function test_observer_does_not_update_outside_ws(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $event = $this->create_course_view_event($course->id, $user->id);
        observer::observe_course_access_event($event);

        $this->assertFalse($DB->record_exists('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]));
    }

    /**
     * Test that non-participating events do not update access.
     */
    public function test_non_participating_event_does_not_update_access(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course, $page, $cm] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $event = $this->create_nonparticipating_wrapper_view_event($course->id, $cm->id, $page->id, $user->id);
        testable_observer::observe_course_access_event($event);

        $this->assertFalse($DB->record_exists('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]));
    }

    /**
     * Test wildcard observer subscriptions receive module-specific course module viewed events.
     */
    public function test_wildcard_observer_receives_module_specific_event(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course, $page] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        testable_observer::reset();

        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '*',
            'callback' => [testable_observer::class, 'prepare_and_observe'],
        ]]);

        \mod_page_external::view_page($page->id);

        $this->assertTrue($DB->record_exists('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]));
        $this->assertContains('\\mod_page\\event\\course_module_viewed', testable_observer::$updatingeventnames);
    }

    /**
     * Test viewing a course through its external function updates course access.
     */
    public function test_course_external_view_updates_access(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course] = $this->create_course_with_page();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        testable_observer::reset();

        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '*',
            'callback' => [testable_observer::class, 'prepare_and_observe'],
        ]]);

        \core_course_external::view_course($course->id);

        $this->assertTrue($DB->record_exists('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]));
        $this->assertContains('\\core\\event\\course_viewed', testable_observer::$updatingeventnames);
    }

    /**
     * Test opening a Book through its external function updates course access.
     */
    public function test_book_external_module_view_updates_access(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course, $book] = $this->create_course_with_book();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        testable_observer::reset();

        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '*',
            'callback' => [testable_observer::class, 'prepare_and_observe'],
        ]]);

        \mod_book_external::view_book($book->id, 0);

        $this->assertTrue($DB->record_exists('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]));
        $this->assertContains('\\mod_book\\event\\course_module_viewed', testable_observer::$updatingeventnames);
        $this->assertContains('\\mod_book\\event\\chapter_viewed', testable_observer::$updatingeventnames);
        $this->assertCount(2, testable_observer::$timeaccesses);
        $this->assertGreaterThan(0, testable_observer::$timeaccesses[0]);
        $this->assertSame(testable_observer::$timeaccesses[0], testable_observer::$timeaccesses[1]);
    }

    /**
     * Test opening a specific Book chapter updates course access through the parent observer.
     */
    public function test_book_external_chapter_view_updates_access(): void {
        global $DB;

        $this->resetAfterTest(true);

        [$course, $book, $chapter] = $this->create_course_with_book();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        testable_observer::reset();

        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '*',
            'callback' => [testable_observer::class, 'prepare_and_observe'],
        ]]);

        \mod_book_external::view_book($book->id, $chapter->id);

        $this->assertTrue($DB->record_exists('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]));
        $this->assertContains('\\mod_book\\event\\chapter_viewed', testable_observer::$updatingeventnames);
    }
}
