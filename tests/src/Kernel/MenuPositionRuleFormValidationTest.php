<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_position\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_position\Entity\MenuPositionRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the validation of the menu position rule form.
 *
 * @group menu_position
 */
#[Group('menu_position')]
#[RunTestsInSeparateProcesses]
class MenuPositionRuleFormValidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'menu_position',
    'path_alias',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'menu_position']);

    MenuLinkContent::create([
      'uuid' => 'a1b2c3d4-1234-5678-9012-123456789012',
      'title' => 'Parent',
      'link' => ['uri' => 'internal:/parent'],
      'menu_name' => 'main',
    ])->save();
  }

  /**
   * Tests that the form accepts only a menu item as the parent.
   *
   * @dataProvider parentProvider
   */
  #[DataProvider('parentProvider')]
  public function testParentValidation(string $parent, bool $expect_error): void {
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('menu_position_rule', 'default')
      ->setEntity(MenuPositionRule::create());
    $form_state = (new FormState())->setValues([
      'label' => 'Validation test',
      'id' => 'validation_test',
      'parent' => $parent,
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);

    $errors = $form_state->getErrors();
    if ($expect_error) {
      $this->assertArrayHasKey('parent', $errors);
      $this->assertSame('Please select a menu item. You have selected the name of a menu.', (string) $errors['parent']);
    }
    else {
      $this->assertSame([], $errors);
    }
  }

  /**
   * Provides parent values and a flag that shows if an error must occur.
   */
  public static function parentProvider(): array {
    return [
      'menu root' => ['main:', TRUE],
      'menu link content item' => ['main:menu_link_content:a1b2c3d4-1234-5678-9012-123456789012', FALSE],
    ];
  }

}
