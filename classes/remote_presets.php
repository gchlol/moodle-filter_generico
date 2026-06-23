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

use cache;
use core\notification;
use DirectoryIterator;
use moodle_exception;

/**
 * Remote preset source — pulls preset bundles from GitHub repository
 * instead of filter/generico/presets directory.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_presets {

    /** Field name used to embed preset's repo-relative path in cached array. Stripped before persisting to plugin config. */
    private const REMOTE_PATH_FIELD = '_remotepath';

    /** Per-request cache of fetched result, including failures (so we don't retry GitHub or spam notifications). */
    private static ?array $requestcache = null;

    /**
     * Fetch presets from GitHub repo plus overrides.
     * Two-level cache: per-request cache handles failures, MUC handles successes (5-min TTL).
     *
     * @return array Preset bundles, sorted by name; empty on failure or no config.
     */
    public static function fetch_presets(): array {
        global $PAGE;

        if (static::$requestcache !== null) {
            return static::$requestcache;
        }

        $cache = cache::make('filter_generico', 'presets');
        $cached = $cache->get('all');
        if ($cached !== false) {
            return static::$requestcache = $cached;
        }

        $presets = static::fetch_presets_remote();

        $themegenericodir = $PAGE->theme->dir . '/generico';
        if (file_exists($themegenericodir)) {
            foreach (new DirectoryIterator($themegenericodir) as $fileinfo) {
                if (
                    $fileinfo->isDot() ||
                    $fileinfo->isDir()
                ) {
                    continue;
                }

                $preset = static::decode_preset(file_get_contents($fileinfo->getPathname()));
                if ($preset) {
                    $presets[] = $preset;
                }
            }
        }

        uasort($presets, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        if (!empty($presets)) {
            $cache->set('all', $presets);
        }
        return static::$requestcache = $presets;
    }

    /**
     * Newer preset version + repo-relative path for a slot, or null. Theme-bundled
     * presets are excluded — per-row update button is a remote-only feature.
     *
     * @param int $templateindex Template slot index (1-based).
     * @return array|null Newer preset as {version, path}, or null if none / up to date / theme-bundled.
     */
    public static function find_update_for_template(int $templateindex): ?array {
        $key = get_config(constants::MOD_FRANKY, 'templatekey_' . $templateindex);
        $stored = get_config(constants::MOD_FRANKY, 'templateversion_' . $templateindex);
        foreach (static::fetch_presets() as $preset) {
            if ($preset['key'] !== $key) {
                continue;
            }
            if (empty($preset[self::REMOTE_PATH_FIELD])) {
                return null;
            }
            if (version_compare($preset['version'], $stored) <= 0) {
                return null;
            }
            return [
                'version' => $preset['version'],
                'path' => $preset[self::REMOTE_PATH_FIELD],
            ];
        }
        return null;
    }

    /**
     * Apply preset. Single-file fetch avoids full-list sweep.
     * Throws hard errors (missing config, path escape, key mismatch). Returns
     * false only for "already up to date" case.
     *
     * @param int $templateindex Template slot index (1-based).
     * @param string $remotepath repo-relative path
     * @return bool true if updated, false if already current
     */
    public static function update_template_by_path(int $templateindex, string $remotepath): bool {
        // Validate path → check config → fetch single bundle → confirm key + newer version → apply.
        if (empty($remotepath)) {
            throw new moodle_exception('missingparam', 'error', '', 'path');
        }
        $repo = get_config(constants::MOD_FRANKY, 'templaterepository');
        $configuredpath = get_config(constants::MOD_FRANKY, 'templaterepositorypath');
        if (empty($repo) || empty($configuredpath)) {
            throw new moodle_exception('repositorynotconfigured', 'filter_generico');
        }
        if (strpos($remotepath, rtrim($configuredpath, '/') . '/') !== 0) {
            throw new moodle_exception('repositorypathoutsideconfigured', 'filter_generico', '', s($remotepath));
        }

        // Resolve via make_github() so tests can inject stub client.
        $github = static::make_github();
        $github->set_repo($repo);

        $preset = static::fetch_preset_body($github, $remotepath);
        if (!$preset) {
            throw new moodle_exception('repositoryerror', 'filter_generico', '', s($remotepath));
        }

        $key = get_config(constants::MOD_FRANKY, 'templatekey_' . $templateindex);
        if ($preset['key'] !== $key) {
            throw new moodle_exception('repositorykeymismatch', 'filter_generico', '', (object) [
                'expected' => s($key),
                'got' => s($preset['key']),
            ]);
        }
        $stored = get_config(constants::MOD_FRANKY, 'templateversion_' . $templateindex);
        if (version_compare($preset['version'], $stored) <= 0) {
            return false;
        }

        presets_control::set_preset_to_config($preset, $templateindex);
        return true;
    }

    /**
     * Apply newer preset to template slot.
     *
     * @param int $templateindex Template slot index (1-based).
     * @return bool true if updated
     */
    public static function update_template(int $templateindex): bool {
        $key = get_config(constants::MOD_FRANKY, 'templatekey_' . $templateindex);
        $stored = get_config(constants::MOD_FRANKY, 'templateversion_' . $templateindex);
        foreach (static::fetch_presets() as $preset) {
            if ($preset['key'] !== $key) {
                continue;
            }
            if (version_compare($preset['version'], $stored) <= 0) {
                return false;
            }
            unset($preset[self::REMOTE_PATH_FIELD]);
            presets_control::set_preset_to_config($preset, $templateindex);

            return true;
        }
        return false;
    }

    /**
     * Apply preset updates to all slot.
     *
     * @return int updated count
     */
    public static function update_all_templates(): int {
        $templatecount = get_config(constants::MOD_FRANKY, 'templatecount');
        $count = 0;
        for ($tindex = 1; $tindex <= $templatecount; $tindex++) {
            if (static::update_template($tindex)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Build GitHub client. Overridable so tests can inject a stub.
     *
     * @return github A GitHub API client.
     */
    protected static function make_github(): github {
        return new github();
    }

    /**
     * Decode preset bundle JSON.
     *
     * @param string $content Raw JSON bundle.
     * @return array|false Decoded preset as assoc array, or false if invalid.
     */
    protected static function decode_preset(string $content) {
        $presetobject = json_decode($content);
        if ($presetobject && is_object($presetobject)) {
            return get_object_vars($presetobject);
        }
        return false;
    }

    /**
     * Fetch and decode a single preset bundle. Surfaces GitHub error responses
     * via admin notification + dev debugging.
     *
     * @param github $github Configured GitHub client.
     * @param string $path Repo-relative path to preset bundle.
     * @return array|false Decoded preset as an assoc array, or false on error/empty.
     */
    protected static function fetch_preset_body(github $github, string $path) {
        $body = $github->get('/contents/' . $path);
        if (empty($body)) {
            return false;
        }
        $fileobject = json_decode($body);
        if (!is_object($fileobject) || empty($fileobject->content)) {
            if (is_object($fileobject) && !empty($fileobject->message)) {
                static::report_repository_error($path, $fileobject->message);
            }
            return false;
        }
        return static::decode_preset(base64_decode(str_replace("\n", '', $fileobject->content)));
    }

    /**
     * Pull preset bundles from repo. Each preset is tagged with its
     * repo-relative path so cache is self-contained.
     *
     * @return array Preset bundles, each tagged with its repo-relative path; empty if unconfigured.
     */
    protected static function fetch_presets_remote(): array {
        $repo = get_config(constants::MOD_FRANKY, 'templaterepository');
        $path = get_config(constants::MOD_FRANKY, 'templaterepositorypath');
        if (empty($repo) || empty($path)) {
            return [];
        }

        $github = static::make_github();
        $github->set_repo($repo);

        $listing = $github->get('/contents/' . $path);
        if (empty($listing)) {
            return [];
        }
        $items = json_decode($listing);
        if (!is_array($items)) {
            if (is_object($items) && !empty($items->message)) {
                static::report_repository_error($path, $items->message);
            }
            return [];
        }

        $presets = [];
        foreach ($items as $item) {
            if ($item->type !== 'file') {
                continue;
            }
            $preset = static::fetch_preset_body($github, $item->path);
            if ($preset) {
                $preset[self::REMOTE_PATH_FIELD] = $item->path;
                $presets[] = $preset;
            }
        }
        return $presets;
    }

    /**
     * Queue an admin-visible warning + dev debug message for GitHub failure.
     *
     * @param string $path repo-relative path that failed
     * @param string $message GitHub-supplied error message
     */
    protected static function report_repository_error(string $path, string $message): void {
        debugging('GitHub error fetching ' . $path . ': ' . $message, DEBUG_DEVELOPER);
        if (!CLI_SCRIPT) {
            notification::warning(get_string('repositoryerror', 'filter_generico', s($message)));
        }
    }
}
