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

// NOTE: behat step files load outside filter_generico namespace, like all behat_* context classes.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps for filter_generico's GitHub-backed presets.
 *
 * Seeds shared MUC presets cache so admin UI renders remote presets
 * offline — no live GitHub call, no token.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_filter_generico extends behat_base {

    /**
     * Seed presets cache with single remote preset for a template slot.
     *
     * @Given /^the generico preset cache holds key "(?P<key>[^"]*)" version "(?P<version>[^"]*)" at path "(?P<path>[^"]*)"$/
     *
     * @param string $key preset key
     * @param string $version preset version
     * @param string $path repo-relative path
     */
    public function the_generico_preset_cache_holds(string $key, string $version, string $path): void {
        \cache::make('filter_generico', 'presets')->set('all', [
            [
                'name' => 'Preset ' . $key,
                'key' => $key,
                'version' => $version,
                'body' => 'BODY-' . $version,
                '_remotepath' => $path,
            ],
        ]);
    }
}
