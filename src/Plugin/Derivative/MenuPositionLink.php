<?php

namespace Drupal\menu_position\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides menu links for Menu Position Rules.
 *
 * @see \Drupal\menu_position\Plugin\Menu\MenuPositionLink
 */
class MenuPositionLink extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    // Reset the discovered definitions.
    $this->derivatives = [];
    foreach ($this->entityTypeManager->getStorage('menu_position_rule')->loadMultiple() as $menu_position_rule) {
      /** @var \Drupal\menu_position\Entity\MenuPositionRule $menu_position_rule */
      /** @var \Drupal\menu_position\Plugin\Menu\MenuPositionLink $menu_link */
      if ($menu_link = $menu_position_rule->getMenuLinkPlugin()) {
        // Link already exists, use that.
        $definition = $menu_link->getPluginDefinition();
        // Parent deletion can move the stored link. Restore the configured
        // location when rebuilding, including after the parent is recreated.
        $definition['menu_name'] = $menu_position_rule->getMenuName();
        $definition['parent'] = $menu_position_rule->getParent();
      }
      else {
        // Provide defaults, they will be updated by the rule.
        $definition = [
          'id' => $base_plugin_definition['id'] . ':' . $menu_position_rule->id(),
          'title' => $this->t('@label (menu position rule)', [
            '@label' => $menu_position_rule->getLabel(),
          ]),
          'menu_name' => $menu_position_rule->getMenuName(),
          'parent' => $menu_position_rule->getParent(),
          'weight' => 0,
          'metadata' => [
            'entity_id' => $menu_position_rule->id(),
          ],
          // Links are enabled (i.e. visible) depending on the modules'
          // settings.
          'enabled' => $this->configFactory->get('menu_position.settings')->get('link_display') === 'child',
        ];
      }
      $this->derivatives[$menu_position_rule->id()] = $definition + $base_plugin_definition;
    }
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
