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
 * GitHub API helper.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_generico;

use filter_generico\constants;

/**
 * Thin curl wrapper for the GitHub Contents API. Mirrors block_configurable_reports\github.
 */
class github extends \curl {

    protected string $repo = '';

    /**
     * Auto-applies the configured token if present.
     *
     * @param array $settings curl settings
     */
    public function __construct($settings = []) {
        parent::__construct($settings);

        $token = get_config(constants::MOD_FRANKY, 'repositorytoken');
        if (!empty($token)) {
            $this->set_token($token);
        }
    }

    /**
     * @param string $repo owner/name
     */
    public function set_repo(string $repo): void {
        $this->repo = $repo;
    }

    /**
     * @param string $token GitHub PAT
     */
    public function set_token(string $token): void {
        $this->setHeader("Authorization: Bearer $token");
    }

    /**
     * GET against the configured repo. URL is appended to /repos/<repo>.
     *
     * @param string $url endpoint path beginning with /
     * @param array $params query params
     * @param array $options curl options
     * @return bool|string raw response or false
     */
    public function get($url, $params = [], $options = []) {
        return parent::get('https://api.github.com/repos/' . $this->repo . $url, $params, $options);
    }
}
