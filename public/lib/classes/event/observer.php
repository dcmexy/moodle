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
     * Update course last access time for participating course events emitted by a webservice request.
     *
     * @param base $event The observed event.
     */
    public static function observe_course_access_event(base $event): void {
        if (!static::should_update_course_access($event)) {
            return;
        }

        user_course_accesstime_log($event->courseid);
    }

    /**
     * Whether the current event should update course last access.
     *
     * @param base $event The observed event.
     * @return bool
     */
    protected static function should_update_course_access(base $event): bool {
        global $USER;

        if (!static::is_webservice_request()) {
            return false;
        }

        if ($event->edulevel !== base::LEVEL_PARTICIPATING) {
            return false;
        }

        if (empty($event->courseid) || (int)$event->courseid === SITEID) {
            return false;
        }

        if (empty($USER->id) || (int)$event->userid !== (int)$USER->id) {
            return false;
        }

        return true;
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
