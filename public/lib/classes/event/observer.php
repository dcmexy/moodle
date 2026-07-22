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

/**
 * Core event observers.
 *
 * @package     core
 * @copyright   2026 Catalyst IT Canada LTD
 * @author      Dean Chimezie <deanchimezie@catalyst-ca.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Update course access time when a module view event is emitted by a webservice request.
     *
     * @param course_module_viewed $event The module view event.
     */
    public static function observe_course_module_viewed(course_module_viewed $event): void {
        global $USER;

        // Do not update access time outside WS execution.
        if (!static::is_webservice_request()) {
            return;
        }

        if (empty($USER->id) || (int)$event->userid !== (int)$USER->id) {
            return;
        }

        user_accesstime_log($event->courseid);
    }

    /**
     * Whether the current request is a webservice request.
     *
     * @return bool
     */
    protected static function is_webservice_request(): bool {
        return defined('WS_SERVER') && WS_SERVER;
    }
}
