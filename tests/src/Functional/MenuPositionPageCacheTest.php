<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_position\Functional;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_position\Entity\MenuPositionRule;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that anonymous cached pages show rule and settings changes.
 *
 * @group menu_position
 */
#[Group('menu_position')]
#[RunTestsInSeparateProcesses]
class MenuPositionPageCacheTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'node',
    'menu_link_content',
    'menu_position',
    'page_cache',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * The path of the page that the rule matches.
   */
  protected string $path;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Role::load(RoleInterface::ANONYMOUS_ID)
      ->grantPermission('access content')
      ->save();
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode(['type' => 'page', 'title' => 'Target']);
    $this->path = '/node/' . $node->id();
    $this->drupalPlaceBlock('system_menu_block:main', ['id' => 'main_menu']);

    $parent_node = $this->drupalCreateNode(['type' => 'page', 'title' => 'Parent']);
    $parent = MenuLinkContent::create([
      'title' => 'Rule parent',
      'link' => ['uri' => 'entity:node/' . $parent_node->id()],
      'menu_name' => 'main',
    ]);
    $parent->save();

    MenuPositionRule::create([
      'id' => 'target',
      'label' => 'Target',
      'enabled' => TRUE,
      'conditions' => [
        'request_path' => [
          'id' => 'request_path',
          'pages' => $this->path,
          'negate' => FALSE,
        ],
      ],
      'menu_name' => 'main',
      'parent' => $parent->getPluginId(),
      'menu_link' => 'menu_position_link:target',
      'weight' => 0,
    ])->save();
  }

  /**
   * Tests that the next page load shows the new active trail.
   */
  public function testChangesShowOnNextPageLoad(): void {
    $this->assertCachedTrail(TRUE);

    // A settings change outside the settings form, for example with drush or a
    // configuration import.
    $this->config('menu_position.settings')->set('link_display', 'none')->save();
    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(FALSE);
    $this->assertCachedTrail(FALSE);

    $this->config('menu_position.settings')->set('link_display', 'parent')->save();
    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(TRUE);
    $this->assertCachedTrail(TRUE);

    // A rule change that changes only the conditions.
    $rule = MenuPositionRule::load('target');
    $rule->getConditions()->addInstanceId('request_path', [
      'id' => 'request_path',
      'pages' => '/other',
      'negate' => FALSE,
    ]);
    $rule->save();
    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(FALSE);
  }

  /**
   * Tests that changing to child mode updates the rendered cached menu.
   */
  public function testChildLinkShowsAfterSettingsChange(): void {
    $child_link = '//li[contains(@class, "menu-item--active-trail")]/a[@data-drupal-link-system-path="' . ltrim($this->path, '/') . '" and text()="Target"]';
    $this->assertCachedTrail(TRUE);
    $this->assertSession()->elementNotExists('xpath', $child_link);

    $this->config('menu_position.settings')->set('link_display', 'child')->save();
    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertSession()->elementExists('xpath', $child_link);
    $this->assertCachedTrail(TRUE);
    $this->assertSession()->elementExists('xpath', $child_link);

    $this->config('menu_position.settings')->set('link_display', 'parent')->save();
    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertSession()->elementNotExists('xpath', $child_link);
    $this->assertCachedTrail(TRUE);
    $this->assertSession()->elementNotExists('xpath', $child_link);
  }

  /**
   * Tests that the settings form updates the next cached page.
   */
  public function testSettingsFormShowsOnNextPageLoad(): void {
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->assertCachedTrail(TRUE);

    $this->drupalLogin($admin);
    $this->drupalGet('/admin/structure/menu-position/settings');
    $this->submitForm(['menu_position_active_link_display' => 'none'], 'Save configuration');
    $this->assertSession()->statusMessageNotExists('error');
    $this->drupalLogout();

    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(FALSE);
  }

  /**
   * Tests that editing rule conditions updates the next cached page.
   */
  public function testRuleFormShowsOnNextPageLoad(): void {
    $admin = $this->drupalCreateUser(['administer menu positions']);
    $this->assertCachedTrail(TRUE);

    $this->drupalLogin($admin);
    $this->drupalGet('/admin/structure/menu-position/target');
    $this->submitForm(['conditions[request_path][pages]' => '/other'], 'Save');
    $this->assertSession()->statusMessageNotExists('error');
    $this->drupalLogout();

    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(FALSE);
  }

  /**
   * Tests that disabling and enabling a rule update cached pages.
   */
  public function testOrderFormShowsOnNextPageLoad(): void {
    $admin = $this->drupalCreateUser(['administer menu positions']);
    $this->assertCachedTrail(TRUE);

    $this->drupalLogin($admin);
    $this->drupalGet('/admin/structure/menu-position');
    $this->submitForm(['rules[target][enabled]' => FALSE], 'Save configuration');
    $this->assertSession()->statusMessageNotExists('error');
    $this->drupalLogout();

    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(FALSE);
    $this->assertCachedTrail(FALSE);

    $this->drupalLogin($admin);
    $this->drupalGet('/admin/structure/menu-position');
    $this->submitForm(['rules[target][enabled]' => TRUE], 'Save configuration');
    $this->assertSession()->statusMessageNotExists('error');
    $this->drupalLogout();

    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(TRUE);
  }

  /**
   * Tests that deleting a rule updates the next cached page.
   */
  public function testDeleteFormShowsOnNextPageLoad(): void {
    $admin = $this->drupalCreateUser(['administer menu positions']);
    $this->assertCachedTrail(TRUE);

    $this->drupalLogin($admin);
    $this->drupalGet('/admin/structure/menu-position/target/delete');
    $this->submitForm([], 'Delete');
    $this->assertSession()->statusMessageNotExists('error');
    $this->drupalLogout();

    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertActiveTrail(FALSE);
  }

  /**
   * Loads the page twice and checks the cached active trail.
   */
  protected function assertCachedTrail(bool $active): void {
    $this->drupalGet($this->path);
    $this->drupalGet($this->path);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');
    $this->assertActiveTrail($active);
  }

  /**
   * Checks if the rule's parent link is in the active trail.
   */
  protected function assertActiveTrail(bool $active): void {
    $xpath = '//li[contains(@class, "menu-item--active-trail")]/a[text()="Rule parent"]';
    if ($active) {
      $this->assertSession()->elementExists('xpath', $xpath);
    }
    else {
      $this->assertSession()->elementNotExists('xpath', $xpath);
      $this->assertSession()->linkExists('Rule parent');
    }
  }

}
