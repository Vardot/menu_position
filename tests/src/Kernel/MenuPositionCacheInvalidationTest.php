<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_position\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_position\Entity\MenuPositionRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that rule and settings changes invalidate the cached menus.
 *
 * Cached pages vary by the 'route.menu_active_trails' cache context. This
 * context adds only the 'config:system.menu.<menu>' cache tag. Each change that
 * can change the active trail must therefore invalidate that tag.
 *
 * @group menu_position
 */
#[Group('menu_position')]
#[RunTestsInSeparateProcesses]
class MenuPositionCacheInvalidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'link',
    'menu_link_content',
    'menu_position',
  ];

  /**
   * The rule in the main menu.
   */
  protected MenuPositionRule $rule;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'menu_position']);

    $parent = MenuLinkContent::create([
      'title' => 'Parent',
      'link' => ['uri' => 'internal:/parent'],
      'menu_name' => 'main',
    ]);
    $parent->save();

    $this->rule = MenuPositionRule::create([
      'id' => 'main_rule',
      'label' => 'Main rule',
      'enabled' => TRUE,
      'conditions' => [
        'request_path' => [
          'id' => 'request_path',
          'pages' => '/target',
          'negate' => FALSE,
        ],
      ],
      'menu_name' => 'main',
      'parent' => $parent->getPluginId(),
      'menu_link' => 'menu_position_link:main_rule',
      'weight' => 0,
    ]);
    $this->rule->save();
    $this->container->get('plugin.manager.menu.link')->rebuild();
  }

  /**
   * Tests that a settings change invalidates the menus that have rules.
   */
  public function testSettingsChange(): void {
    foreach (['child', 'none', 'parent'] as $link_display) {
      $this->cacheMenuPage('main');
      $this->cacheMenuPage('footer');
      $this->config('menu_position.settings')->set('link_display', $link_display)->save();
      $this->assertFalse($this->isMenuPageCached('main'), "Changing link_display to '$link_display' invalidates the main menu.");
      // No rule uses the footer menu.
      $this->assertTrue($this->isMenuPageCached('footer'));
    }

    // A save without a change keeps the cached pages.
    $this->cacheMenuPage('main');
    $this->config('menu_position.settings')->save();
    $this->assertTrue($this->isMenuPageCached('main'));
  }

  /**
   * Tests that each rule change invalidates the menu of the rule.
   */
  public function testRuleChanges(): void {
    $changes = [
      'conditions only' => function (MenuPositionRule $rule) {
        $rule->getConditions()->addInstanceId('request_path', [
          'id' => 'request_path',
          'pages' => '/other',
          'negate' => FALSE,
        ]);
        $rule->save();
      },
      'disable' => function (MenuPositionRule $rule) {
        $rule->setEnabled(FALSE);
        $rule->save();
      },
      'reorder' => function (MenuPositionRule $rule) {
        $rule->setWeight(5);
        $rule->save();
      },
      'delete' => function (MenuPositionRule $rule) {
        $rule->delete();
      },
    ];
    foreach ($changes as $label => $change) {
      $this->cacheMenuPage('main');
      $change(MenuPositionRule::load($this->rule->id()));
      $this->assertFalse($this->isMenuPageCached('main'), "Rule change '$label' invalidates the main menu.");
    }
  }

  /**
   * Tests that saving a new rule directly creates its link and expires pages.
   */
  public function testProgrammaticRuleCreation(): void {
    $this->cacheMenuPage('main');
    $this->cacheMenuPage('footer');

    $rule = MenuPositionRule::create([
      'id' => 'new_rule',
      'label' => 'New rule',
      'enabled' => TRUE,
      'conditions' => [],
      'menu_name' => 'main',
      'parent' => $this->rule->getParent(),
      'menu_link' => 'menu_position_link:new_rule',
      'weight' => 0,
    ]);
    $rule->save();

    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $this->assertTrue($menu_link_manager->hasDefinition($rule->getMenuLink()));
    $this->assertSame($rule->getParent(), $menu_link_manager->getDefinition($rule->getMenuLink())['parent']);
    $this->assertFalse($this->isMenuPageCached('main'));
    $this->assertTrue($this->isMenuPageCached('footer'));
  }

  /**
   * Tests that creating a rule through the form keeps other menus cached.
   */
  public function testFormCreationOnlyInvalidatesAffectedMenu(): void {
    MenuLinkContent::create([
      'title' => 'Footer link',
      'link' => ['uri' => 'internal:/footer'],
      'menu_name' => 'footer',
    ])->save();
    $this->cacheMenuPage('main');
    $this->cacheMenuPage('footer');

    $rule = MenuPositionRule::create([
      'id' => 'form_rule',
      'label' => 'Form rule',
      'enabled' => TRUE,
      'conditions' => [],
      'menu_name' => 'main',
      'parent' => $this->rule->getParent(),
      'weight' => 0,
    ]);
    $rule_form = $this->container->get('entity_type.manager')
      ->getFormObject('menu_position_rule', 'default');
    $rule_form->setEntity($rule);
    $form_state = new FormState();
    $form_state->setValue('conditions', []);
    $rule_form->save([], $form_state);

    $this->assertTrue($this->container->get('plugin.manager.menu.link')->hasDefinition($rule->getMenuLink()));
    $this->assertFalse($this->isMenuPageCached('main'));
    $this->assertTrue($this->isMenuPageCached('footer'));
  }

  /**
   * Tests that editing a rule restores a missing link without a full rebuild.
   */
  public function testFormUpdateRestoresMissingLink(): void {
    MenuLinkContent::create([
      'title' => 'Footer link',
      'link' => ['uri' => 'internal:/footer'],
      'menu_name' => 'footer',
    ])->save();
    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $menu_link_manager->removeDefinition($this->rule->getMenuLink());
    $this->cacheMenuPage('footer');

    $rule_form = $this->container->get('entity_type.manager')
      ->getFormObject('menu_position_rule', 'default');
    $rule_form->setEntity($this->rule);
    $form_state = new FormState();
    $form_state->setValue('conditions', []);
    $rule_form->save([], $form_state);

    $this->assertTrue($menu_link_manager->hasDefinition($this->rule->getMenuLink()));
    $this->assertTrue($this->isMenuPageCached('footer'));
  }

  /**
   * Caches an item with the tags of a page that shows the active trail.
   */
  protected function cacheMenuPage(string $menu_name): void {
    $tags = $this->container->get('cache_context.route.menu_active_trails')
      ->getCacheableMetadata($menu_name)
      ->getCacheTags();
    $this->container->get('cache.render')->set('page:' . $menu_name, TRUE, Cache::PERMANENT, $tags);
  }

  /**
   * Checks if the cached item for a menu is still valid.
   */
  protected function isMenuPageCached(string $menu_name): bool {
    return (bool) $this->container->get('cache.render')->get('page:' . $menu_name);
  }

}
