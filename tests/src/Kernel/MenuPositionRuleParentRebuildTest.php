<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_position\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_position\Entity\MenuPositionRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests restoring a rule's parent after menu links are recreated.
 */
#[Group('menu_position')]
class MenuPositionRuleParentRebuildTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'menu_position',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'menu_position']);
  }

  /**
   * Tests rebuilding after rollback and reimport without saving rule config.
   */
  #[DataProvider('parentLocationProvider')]
  public function testRebuildRestoresRecreatedParent(bool $nested_parent): void {
    $grandparent_plugin_id = '';
    if ($nested_parent) {
      $grandparent = MenuLinkContent::create([
        'title' => 'Grandparent',
        'link' => ['uri' => 'internal:/grandparent'],
        'menu_name' => 'main',
      ]);
      $grandparent->save();
      $grandparent_plugin_id = $grandparent->getPluginId();
    }

    $parent_values = [
      'uuid' => 'a1b2c3d4-1234-5678-9012-123456789012',
      'title' => 'Migrated parent',
      'parent' => $grandparent_plugin_id,
      'link' => ['uri' => 'internal:/parent'],
      'menu_name' => 'main',
    ];
    $parent = MenuLinkContent::create($parent_values);
    $parent->save();
    $parent_plugin_id = $parent->getPluginId();

    $rule = MenuPositionRule::create([
      'id' => 'recreated_parent',
      'label' => 'Recreated parent',
      'enabled' => TRUE,
      'conditions' => [],
      'menu_name' => 'main',
      'parent' => $parent_plugin_id,
      'menu_link' => 'menu_position_link:recreated_parent',
      'weight' => 0,
    ]);
    $rule->save();

    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $menu_link_manager->rebuild();
    $definition = $menu_link_manager->getDefinition($rule->getMenuLink());
    $this->assertSame($parent_plugin_id, $definition['parent']);

    // Menu ordering is stored on the link, separately from rule priority.
    $menu_link_manager->updateDefinition($rule->getMenuLink(), ['weight' => 7]);
    $original_config = $rule->toArray();

    // Rollback removes the content link while the rule remains in config.
    $parent->delete();
    $definition = $menu_link_manager->getDefinition($rule->getMenuLink());
    $this->assertSame($grandparent_plugin_id, $definition['parent']);
    $menu_link_manager->rebuild();
    $definition = $menu_link_manager->getDefinition($rule->getMenuLink());
    $this->assertSame('', $definition['parent']);

    // Reimport recreates the parent with its deterministic UUID.
    $parent = MenuLinkContent::create($parent_values);
    $parent->save();
    $this->assertSame($parent_plugin_id, $parent->getPluginId());
    $menu_link_manager->rebuild();

    $definition = $menu_link_manager->getDefinition($rule->getMenuLink());
    $this->assertSame($parent_plugin_id, $definition['parent']);
    $this->assertSame('main', $definition['menu_name']);
    $this->assertEquals(7, $definition['weight']);
    $this->assertArrayHasKey($parent_plugin_id, $menu_link_manager->getParentIds($rule->getMenuLink()));
    $this->assertSame($original_config, MenuPositionRule::load($rule->id())->toArray());
  }

  /**
   * Provides the parent locations before deletion.
   */
  public static function parentLocationProvider(): array {
    return [
      'top-level parent' => [FALSE],
      'nested parent' => [TRUE],
    ];
  }

  /**
   * Tests restoring the configured menu while the parent is unavailable.
   */
  public function testRebuildRestoresMenuWithoutParent(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Movable parent',
      'link' => ['uri' => 'internal:/parent'],
      'menu_name' => 'main',
    ]);
    $parent->save();

    $rule = MenuPositionRule::create([
      'id' => 'moved_parent',
      'label' => 'Moved parent',
      'enabled' => TRUE,
      'conditions' => [],
      'menu_name' => 'main',
      'parent' => $parent->getPluginId(),
      'menu_link' => 'menu_position_link:moved_parent',
      'weight' => 0,
    ]);
    $rule->save();

    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $menu_link_manager->rebuild();
    $original_config = $rule->toArray();

    // Moving a parent moves its children, without changing rule configuration.
    $parent->set('menu_name', 'footer')->save();
    $definition = $menu_link_manager->getDefinition($rule->getMenuLink());
    $this->assertSame('footer', $definition['menu_name']);
    $parent->delete();

    // Without a parent, the rule's configured menu determines its location.
    $menu_link_manager->rebuild();
    $definition = $menu_link_manager->getDefinition($rule->getMenuLink());
    $this->assertSame('', $definition['parent']);
    $this->assertSame('main', $definition['menu_name']);
    $this->assertSame($original_config, MenuPositionRule::load($rule->id())->toArray());
  }

}
