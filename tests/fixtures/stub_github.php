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
 * Offline GitHub stub for unit tests — returns canned response bodies keyed by
 * relative request URL, never touching the network.
 *
 * @package    filter_generico
 * @copyright  2026 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stub_github extends github {

    /** Map of relative request URL => canned response body (string), or false for failed request. */
    public array $responses = [];

    /**
     * Return canned response for a URL, or false when none is registered.
     *
     * @param string $url
     * @param array $params
     * @param array $options
     * @return bool|string
     */
    public function get($url, $params = [], $options = []) {
        return $this->responses[$url] ?? false;
    }

    /**
     * Register a directory-listing response for a repo path.
     *
     * @param string $path repo-relative directory path
     * @param array $files list of repo-relative file paths to list
     */
    public function set_listing(string $path, array $files): void {
        $items = [];
        foreach ($files as $file) {
            $items[] = (object) ['type' => 'file', 'path' => $file];
        }
        $this->responses['/contents/' . $path] = json_encode($items);
    }

    /**
     * Register single file's content response.
     *
     * @param string $path repo-relative file path
     * @param array $preset preset bundle to encode
     */
    public function set_file(string $path, array $preset): void {
        $this->responses['/contents/' . $path] = json_encode([
            'content' => base64_encode(json_encode($preset)),
        ]);
    }

    /**
     * Register a GitHub error object response.
     *
     * @param string $path repo-relative path
     * @param string $message GitHub error message
     */
    public function set_error(string $path, string $message): void {
        $this->responses['/contents/' . $path] = json_encode(['message' => $message]);
    }
}
