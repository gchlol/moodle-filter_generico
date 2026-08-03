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

namespace filter_generico;

/**
 * Test subclass that injects a stub GitHub client via make_github() seam,
 * so fetch/decode paths run in-process with no network.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_remote_presets extends remote_presets {

    /** Stub returned by make_github(). */
    public static ?github $stub = null;

    /**
     * Return registered stub instead of a real GitHub client.
     *
     * @return github
     */
    protected static function make_github(): github {
        return static::$stub ?? parent::make_github();
    }
}
