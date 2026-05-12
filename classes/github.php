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

use curl;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * GitHub API helper.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class github extends curl {

    protected string $repo = '';

    /**
     * Auto-applies the configured token if present.
     *
     * @param array $settings
     */
    public function __construct(array $settings = []) {
        parent::__construct($settings);

        $token = get_config(constants::MOD_FRANKY, 'repositorytoken');
        if (!empty($token)) {
            $this->set_token($token);
        }
    }

    /**
     * Set the target repository.
     *
     * @param string $repo owner/name
     */
    public function set_repo(string $repo): void {
        $this->repo = $repo;
    }

    /**
     * Set the bearer token.
     *
     * @param string $token
     */
    public function set_token(string $token): void {
        $this->setHeader("Authorization: Bearer $token");
    }

    /**
     * GET against /repos/<repo><url>.
     *
     * @param string $url
     * @param array $params
     * @param array $options
     * @return bool|string
     */
    public function get($url, $params = [], $options = []) {
        return parent::get('https://api.github.com/repos/' . $this->repo . $url, $params, $options);
    }
}
