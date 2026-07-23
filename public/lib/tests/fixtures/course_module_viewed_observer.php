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

/**
 * Test fixture for the course module viewed observer.
 *
 * @package     core
 * @category    test
 * @copyright   2026 Catalyst IT Canada LTD
 * @author      Dean Chimezie <deanchimezie@catalyst-ca.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_tests\event;

use core\event\course_module_viewed;
use core\event\observer;

/**
 * Test observer which simulates a webservice request.
 */

/**
 * Test observer which simulates a webservice request.
 */
class testable_observer extends observer {
    /** @var int Number of observer invocations. */
    public static int $invocations = 0;

    /** @var int[] Access times recorded after each invocation. */
    public static array $timeaccesses = [];

    /** @var bool[] Courses prepared for access-update assertions. */
    private static array $preparedcourses = [];

    /**
     * Reset the observer state.
     */
    public static function reset(): void {
        self::$invocations = 0;
        self::$timeaccesses = [];
        self::$preparedcourses = [];
    }

    /**
     * Remove non-WS require_login access once before invoking the production callback.
     *
     * @param course_module_viewed $event The module view event.
     */
    public static function prepare_and_observe(course_module_viewed $event): void {
        global $DB, $USER;

        self::$invocations++;
        $key = $USER->id . ':' . $event->courseid;
        if (empty(self::$preparedcourses[$key])) {
            $DB->delete_records('user_lastaccess', ['userid' => $USER->id, 'courseid' => $event->courseid]);
            unset($USER->currentcourseaccess[$event->courseid]);
            self::$preparedcourses[$key] = true;
        }

        parent::observe_course_module_viewed($event);
        self::$timeaccesses[] = (int) $DB->get_field('user_lastaccess', 'timeaccess', [
            'userid' => $USER->id,
            'courseid' => $event->courseid,
        ]);
    }

    /**
     * Simulate WS execution for observer tests.
     *
     * @return bool
     */
    protected static function is_webservice_request(): bool {
        return true;
    }
}
