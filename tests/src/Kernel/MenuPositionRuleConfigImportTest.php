<?php

namespace Drupal\Tests\menu_position\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests updating a menu position rule through configuration import.
 *
 * @group menu_position
 */
#[Group('menu_position')]
#[RunTestsInSeparateProcesses]
class MenuPositionRuleConfigImportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'menu_position',
    'menu_position_test',
  ];

  /**
   * Tests importing a rule into a different parent and menu.
   */
  public function testImportUpdatesMenuLinkParentAndMenu(): void {
    $this->installConfig(['system', 'menu_position']);

    $rule_id = 'config_import_test';
    $menu_link_id = 'menu_position_link:' . $rule_id;
    $config_name = 'menu_position.menu_position_rule.' . $rule_id;

    // Create a rule beneath a parent in the first menu.
    $rule = $this->container
      ->get('entity_type.manager')
      ->getStorage('menu_position_rule')
      ->create([
        'id' => $rule_id,
        'label' => 'Config import test',
        'enabled' => TRUE,
        'conditions' => [],
        'menu_name' => 'first',
        'parent' => 'menu_position_test.first_parent',
        'menu_link' => $menu_link_id,
        'weight' => 0,
      ]);
    $rule->save();

    // Build and verify the rule's initial menu link.
    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $menu_link_manager->rebuild();

    $definition = $menu_link_manager->getDefinition($menu_link_id);
    $this->assertSame('menu_position_test.first_parent', $definition['parent']);
    $this->assertSame('first', $definition['menu_name']);

    // Change the rule's parent and menu in the sync storage.
    $active_storage = $this->container->get('config.storage');
    $sync_storage = $this->container->get('config.storage.sync');
    $this->copyConfig($active_storage, $sync_storage);

    $config_data = $sync_storage->read($config_name);
    $config_data['parent'] = 'menu_position_test.second_parent';
    $config_data['menu_name'] = 'second';
    $sync_storage->write($config_name, $config_data);

    // Import the updated rule as a configuration synchronization would.
    $this->configImporter()->import();

    // Confirm the imported values reached the configuration entity.
    $rule_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('menu_position_rule');
    $rule_storage->resetCache([$rule_id]);
    $imported_rule = $rule_storage->load($rule_id);
    $this->assertSame('menu_position_test.second_parent', $imported_rule->getParent());
    $this->assertSame('second', $imported_rule->getMenuName());

    // Confirm the derived menu link moved with the imported rule.
    $definition = $menu_link_manager->getDefinition($menu_link_id);
    $this->assertSame('menu_position_test.second_parent', $definition['parent']);
    $this->assertSame('second', $definition['menu_name']);
  }

}
