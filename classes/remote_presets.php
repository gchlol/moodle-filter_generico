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
 * Remote preset source — pulls preset bundles from a configured GitHub repository
 * instead of the on-disk filter/generico/presets directory.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_generico;

/**
 * Sibling of \filter_generico\presets_control. Upstream call sites are diverted here so
 * the third-party presets_control class stays pristine and merge-clean against upstream.
 */
class remote_presets {

    /**
     * Fetch presets from the configured GitHub repo, plus any theme-bundled overrides.
     *
     * @return array
     */
    public static function fetch_presets(): array {
        global $PAGE;
        $ret = static::fetch_presets_remote();

        $themegenericodir = $PAGE->theme->dir . '/generico';
        if (file_exists($themegenericodir)) {
            foreach (new \DirectoryIterator($themegenericodir) as $fileinfo) {
                if ($fileinfo->isDot() || $fileinfo->isDir()) {
                    continue;
                }
                $preset = static::decode_preset(file_get_contents($fileinfo->getPathname()));
                if ($preset) {
                    $ret[] = $preset;
                }
            }
        }

        uasort($ret, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $ret;
    }

    /**
     * Apply newer preset to a configured template if one exists.
     *
     * @param int $templateindex
     * @return bool true if updated
     */
    public static function update_template(int $templateindex): bool {
        $key = get_config(constants::MOD_FRANKY, 'templatekey_' . $templateindex);
        foreach (static::fetch_presets() as $preset) {
            if ($preset['key'] !== $key) {
                continue;
            }
            $stored = get_config(constants::MOD_FRANKY, 'templateversion_' . $templateindex);
            if (version_compare($preset['version'], $stored) > 0) {
                presets_control::set_preset_to_config($preset, $templateindex);
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Apply preset updates across every template slot.
     *
     * @return int number of templates updated
     */
    public static function update_all_templates(): int {
        $templatecount = get_config(constants::MOD_FRANKY, 'templatecount');
        $count = 0;
        for ($x = 1; $x <= $templatecount; $x++) {
            if (static::update_template($x)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * If the configured template has a newer preset available, return that version.
     *
     * @param int $templateindex
     * @return string|false new version, or false when no update
     */
    public static function template_has_update(int $templateindex) {
        $key = get_config(constants::MOD_FRANKY, 'templatekey_' . $templateindex);
        foreach (static::fetch_presets() as $preset) {
            if ($preset['key'] !== $key) {
                continue;
            }
            $stored = get_config(constants::MOD_FRANKY, 'templateversion_' . $templateindex);
            if (version_compare($preset['version'], $stored) > 0) {
                return $preset['version'];
            }
        }
        return false;
    }

    /**
     * Decode a preset bundle JSON string into an associative array.
     *
     * @param string $content raw preset JSON
     * @return array|false
     */
    protected static function decode_preset(string $content) {
        $presetobject = json_decode($content);
        if ($presetobject && is_object($presetobject)) {
            return get_object_vars($presetobject);
        }
        return false;
    }

    /**
     * Pull preset bundles from the configured GitHub repository.
     *
     * @return array
     */
    protected static function fetch_presets_remote(): array {
        $repo = get_config(constants::MOD_FRANKY, 'templaterepository');
        $path = get_config(constants::MOD_FRANKY, 'templaterepositorypath');
        if (empty($repo) || empty($path)) {
            return [];
        }

        $github = new github();
        $github->set_repo($repo);

        $listing = $github->get('/contents/' . $path);
        if (empty($listing)) {
            return [];
        }
        $items = json_decode($listing);
        if (!is_array($items)) {
            return [];
        }

        $ret = [];
        foreach ($items as $item) {
            if ($item->type !== 'file') {
                continue;
            }
            $filebody = $github->get('/contents/' . $item->path);
            if (empty($filebody)) {
                continue;
            }
            $fileobject = json_decode($filebody);
            if (!is_object($fileobject) || empty($fileobject->content)) {
                continue;
            }
            $preset = static::decode_preset(base64_decode(str_replace("\n", '', $fileobject->content)));
            if ($preset) {
                $ret[] = $preset;
            }
        }
        return $ret;
    }
}
