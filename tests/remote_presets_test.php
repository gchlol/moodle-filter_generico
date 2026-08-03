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

use advanced_testcase;
use cache;
use moodle_exception;
use ReflectionProperty;

/**
 * Tests for GitHub-backed remote preset source.
 *
 * Network paths (actual GitHub fetch) are not exercised — instead MUC
 * cache is pre-seeded so fetch_presets() returns deterministically without a
 * curl call. Guard clauses of update_template_by_path() throw before any
 * network access, so they are tested directly.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_generico\remote_presets
 */
final class remote_presets_test extends advanced_testcase {

    /** Plugin frankenstyle component. */
    private const COMPONENT = 'filter_generico';

    /**
     * Reset DB/config after each test and clear the per-request cache so a
     * seeded cache in one test never leaks into the next.
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/stub_github.php');
        require_once(__DIR__ . '/fixtures/testable_remote_presets.php');
        $this->resetAfterTest();
        $requestcache = new ReflectionProperty(remote_presets::class, 'requestcache');
        $requestcache->setAccessible(true);
        $requestcache->setValue(null, null);
        testable_remote_presets::$stub = null;
    }

    /**
     * Register a stub GitHub client for seam-driven (network-path) tests.
     *
     * @return stub_github
     */
    private function install_stub_github(): stub_github {
        $stub = new stub_github();
        testable_remote_presets::$stub = $stub;
        return $stub;
    }

    /**
     * Pre-seed presets MUC cache so fetch_presets() skips GitHub call.
     *
     * @param array $presets list of preset arrays
     */
    private function seed_cache(array $presets): void {
        cache::make('filter_generico', 'presets')->set('all', $presets);
    }

    /**
     * Build minimal preset array.
     *
     * @param string $key template key
     * @param string $version semantic version
     * @param string|null $remotepath repo-relative path, or null for theme-bundled preset
     * @return array
     */
    private function make_preset(string $key, string $version, ?string $remotepath = null): array {
        $preset = [
            'name' => 'Preset ' . $key,
            'key' => $key,
            'version' => $version,
            'body' => 'BODY-' . $version,
        ];
        if ($remotepath !== null) {
            $preset['_remotepath'] = $remotepath;
        }
        return $preset;
    }

    /**
     * Reject empthy path before any config read or network call.
     */
    public function test_update_template_by_path_rejects_empty_path(): void {
        try {
            remote_presets::update_template_by_path(1, '');
            $this->fail('Expected a moodle_exception for an empty path');
        } catch (moodle_exception $e) {
            $this->assertSame('missingparam', $e->errorcode);
        }
    }

    /**
     * With repo/path settings empty, non-empty path still errors out.
     */
    public function test_update_template_by_path_requires_configuration(): void {
        set_config('templaterepository', '', self::COMPONENT);
        set_config('templaterepositorypath', '', self::COMPONENT);

        try {
            remote_presets::update_template_by_path(1, 'export/welcome.json');
            $this->fail('Expected a moodle_exception when the repository is unconfigured');
        } catch (moodle_exception $e) {
            $this->assertSame('repositorynotconfigured', $e->errorcode);
        }
    }

    /**
     * Path-traversal guard: path outside configured directory is refused.
     */
    public function test_update_template_by_path_blocks_path_outside_configured(): void {
        set_config('templaterepository', 'owner/repo', self::COMPONENT);
        set_config('templaterepositorypath', 'export', self::COMPONENT);

        try {
            remote_presets::update_template_by_path(1, 'evil/escape.txt');
            $this->fail('Expected a moodle_exception for a path outside the configured directory');
        } catch (moodle_exception $e) {
            $this->assertSame('repositorypathoutsideconfigured', $e->errorcode);
        }
    }

    /**
     * Prefix-only matches (export vs exportsneaky) must not satisfy guard —
     * trailing slash is required.
     */
    public function test_update_template_by_path_blocks_sibling_prefix(): void {
        set_config('templaterepository', 'owner/repo', self::COMPONENT);
        set_config('templaterepositorypath', 'export', self::COMPONENT);

        try {
            remote_presets::update_template_by_path(1, 'exportsneaky/escape.txt');
            $this->fail('Expected a moodle_exception for a sibling-prefix path');
        } catch (moodle_exception $e) {
            $this->assertSame('repositorypathoutsideconfigured', $e->errorcode);
        }
    }

    /**
     * New remote preset reports its version + repo path for the slot.
     */
    public function test_find_update_for_template_returns_newer(): void {
        set_config('templatekey_1', 'welcome', self::COMPONENT);
        set_config('templateversion_1', '1.0', self::COMPONENT);
        $this->seed_cache([$this->make_preset('welcome', '2.0', 'export/welcome.json')]);

        $update = remote_presets::find_update_for_template(1);

        $this->assertSame(['version' => '2.0', 'path' => 'export/welcome.json'], $update);
    }

    /**
     * Remote preset that is not newer than stored version yields no update.
     */
    public function test_find_update_for_template_skips_when_not_newer(): void {
        set_config('templatekey_1', 'welcome', self::COMPONENT);
        set_config('templateversion_1', '2.0', self::COMPONENT);
        $this->seed_cache([$this->make_preset('welcome', '2.0', 'export/welcome.json')]);

        $this->assertNull(remote_presets::find_update_for_template(1));
    }

    /**
     * Preset whose key does not match slot is ignored.
     */
    public function test_find_update_for_template_ignores_key_mismatch(): void {
        set_config('templatekey_1', 'welcome', self::COMPONENT);
        set_config('templateversion_1', '1.0', self::COMPONENT);
        $this->seed_cache([$this->make_preset('different', '2.0', 'export/different.json')]);

        $this->assertNull(remote_presets::find_update_for_template(1));
    }

    /**
     * Theme-bundled presets (no repo path) never offer a per-row update.
     */
    public function test_find_update_for_template_excludes_theme_bundled(): void {
        set_config('templatekey_1', 'welcome', self::COMPONENT);
        set_config('templateversion_1', '1.0', self::COMPONENT);
        $this->seed_cache([$this->make_preset('welcome', '2.0', null)]);

        $this->assertNull(remote_presets::find_update_for_template(1));
    }

    /**
     * Applying newer preset writes mapped fields into plugin config.
     */
    public function test_update_template_applies_newer_preset(): void {
        set_config('templatekey_1', 'welcome', self::COMPONENT);
        set_config('templateversion_1', '1.0', self::COMPONENT);
        $this->seed_cache([$this->make_preset('welcome', '2.0', 'export/welcome.json')]);

        $this->assertTrue(remote_presets::update_template(1));
        $this->assertSame('2.0', get_config(self::COMPONENT, 'templateversion_1'));
        $this->assertSame('BODY-2.0', get_config(self::COMPONENT, 'template_1'));
        // Internal repo-path field must never leak into persisted config.
        $this->assertFalse(get_config(self::COMPONENT, '_remotepath_1'));
    }

    /**
     * No write occurs when remote preset is not newer.
     */
    public function test_update_template_noop_when_not_newer(): void {
        set_config('templatekey_1', 'welcome', self::COMPONENT);
        set_config('templateversion_1', '2.0', self::COMPONENT);
        set_config('template_1', 'ORIGINAL', self::COMPONENT);
        $this->seed_cache([$this->make_preset('welcome', '2.0', 'export/welcome.json')]);

        $this->assertFalse(remote_presets::update_template(1));
        $this->assertSame('ORIGINAL', get_config(self::COMPONENT, 'template_1'));
    }

    /**
     * update_all_templates applies every slot with a newer preset and counts them.
     */
    public function test_update_all_templates_counts_applied(): void {
        set_config('templatecount', 2, self::COMPONENT);
        set_config('templatekey_1', 'alpha', self::COMPONENT);
        set_config('templateversion_1', '1.0', self::COMPONENT);
        set_config('templatekey_2', 'beta', self::COMPONENT);
        set_config('templateversion_2', '3.0', self::COMPONENT);
        $this->seed_cache([
            // Slot 1 has a newer preset, slot 2 is already current.
            $this->make_preset('alpha', '2.0', 'export/alpha.json'),
            $this->make_preset('beta', '3.0', 'export/beta.json'),
        ]);

        $this->assertSame(1, remote_presets::update_all_templates());
        $this->assertSame('2.0', get_config(self::COMPONENT, 'templateversion_1'));
        $this->assertSame('3.0', get_config(self::COMPONENT, 'templateversion_2'));
    }

    /**
     * fetch_presets returns cached listing verbatim, proving cache hit
     * short-circuits GitHub call.
     */
    public function test_fetch_presets_returns_cached_without_network(): void {
        $presets = [$this->make_preset('welcome', '1.0', 'export/welcome.json')];
        $this->seed_cache($presets);

        $this->assertSame($presets, remote_presets::fetch_presets());
    }

    /**
     * Remote listing is fetched, each bundle decoded, tagged with its repo
     * path, and natural-sorted by name — all in-process via the stub.
     */
    public function test_fetch_presets_remote_decodes_and_tags(): void {
        set_config('templaterepository', 'owner/repo', self::COMPONENT);
        set_config('templaterepositorypath', 'export', self::COMPONENT);

        $stub = $this->install_stub_github();
        $stub->set_listing('export', ['export/bravo.json', 'export/alpha.json']);
        $stub->set_file('export/bravo.json', $this->make_preset('bravo', '1.0'));
        $stub->set_file('export/alpha.json', $this->make_preset('alpha', '1.0'));

        $presets = testable_remote_presets::fetch_presets();

        $this->assertCount(2, $presets);
        // Natural-sorted by name: "Preset alpha" before "Preset bravo".
        $presets = array_values($presets);
        $this->assertSame('Preset alpha', $presets[0]['name']);
        $this->assertSame('Preset bravo', $presets[1]['name']);
        // Each preset carries its repo-relative path for the single-file update path.
        $this->assertSame('export/alpha.json', $presets[0]['_remotepath']);
        $this->assertSame('export/bravo.json', $presets[1]['_remotepath']);
    }

    /**
     * GitHub error body yields an empty result and a developer debug message.
     * (The notification::warning branch is CLI-suppressed under PHPUnit.)
     */
    public function test_fetch_presets_remote_reports_github_error(): void {
        set_config('templaterepository', 'owner/repo', self::COMPONENT);
        set_config('templaterepositorypath', 'export', self::COMPONENT);

        $stub = $this->install_stub_github();
        $stub->set_error('export', 'Not Found');

        $this->assertSame([], testable_remote_presets::fetch_presets());
        $this->assertDebuggingCalled('GitHub error fetching export: Not Found', DEBUG_DEVELOPER);
    }

    /**
     * Single-file update success path: stub returns bundle, key matches,
     * version is newer, config is written.
     */
    public function test_update_template_by_path_applies_single_file(): void {
        set_config('templaterepository', 'owner/repo', self::COMPONENT);
        set_config('templaterepositorypath', 'export', self::COMPONENT);
        set_config('templatekey_1', 'welcome', self::COMPONENT);
        set_config('templateversion_1', '1.0', self::COMPONENT);

        $stub = $this->install_stub_github();
        $stub->set_file('export/welcome.json', $this->make_preset('welcome', '2.0'));

        $this->assertTrue(testable_remote_presets::update_template_by_path(1, 'export/welcome.json'));
        $this->assertSame('2.0', get_config(self::COMPONENT, 'templateversion_1'));
        $this->assertSame('BODY-2.0', get_config(self::COMPONENT, 'template_1'));
    }
}
