@filter @filter_generico
Feature: Pull Generico presets from a GitHub repository
  In order to update Generico templates without a redeploy
  As an administrator
  I need the remote-preset settings and update affordances to appear in the admin UI

  Scenario: New repository settings appear on the Generico settings page
    Given I log in as "admin"
    And the following config values are set as admin:
      | templaterepository |  | filter_generico |
    When I visit "/admin/settings.php?section=filtersettinggenerico"
    Then I should see "Template repository"
    And I should see "Template repository path"
    And I should see "GitHub access token"

  Scenario: A newer remote preset shows an update button on the templates admin page
    Given I log in as "admin"
    And the following config values are set as admin:
      | templatecount     | 1       | filter_generico |
      | templatekey_1     | welcome | filter_generico |
      | templateversion_1 | 1.0     | filter_generico |
    And the generico preset cache holds key "welcome" version "2.0" at path "export/welcome.json"
    When I visit "/filter/generico/genericotemplatesadmin.php"
    Then I should see "Generico Templates Admin"
    And I should see "Update to version: 2.0"


