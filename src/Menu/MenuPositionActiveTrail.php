<?php

namespace Drupal\menu_position\Menu;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Menu\MenuActiveTrail;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

class MenuPositionActiveTrail extends MenuActiveTrail  {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity query factory service.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactoryInterface
   */
  protected $entity_query;

  /**
   * Menu position settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Constructs a \Drupal\Core\Menu\MenuActiveTrail object.
   *
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   *   The menu link plugin manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A route match object for finding the active link.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend service.
   * @param \Drupal\Core\Entity\Query\QueryFactoryInterface $entity_query
   *   The query factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(
    MenuLinkManagerInterface $menu_link_manager,
    RouteMatchInterface $route_match,
    CacheBackendInterface $cache,
    LockBackendInterface $lock,
    QueryFactoryInterface $entity_query,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory) {

    parent::__construct($menu_link_manager, $route_match, $cache, $lock);
    $this->entityTypeManager = $entity_type_manager;
    $this->entity_query = $entity_query;
    $this->settings = $config_factory->get('menu_position.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveLink($menu_name = NULL) {
    // Get all the rules.
    $query = $this->entity_query->get('menu_position_rule');

    // Filter on the menu name if there is one.
    if (!is_null($menu_name)) {
      $query->condition('menu_name', $menu_name);
    }

    $results = $query->sort('weight')->execute();
    $rules = $this->entityTypeManager->getStorage('menu_position_rule')->loadMultiple($results);

    // Iterate over the rules.
    foreach ($rules as $rule) {
      // This rule is active.
      if ($rule->isActive()) {
        $menu_link = $this->menuLinkManager->createInstance($rule->getMenuLink());
        switch ($this->settings->get('link_display')) {
          case 'child':
            // Set this menu link to active.
            return $menu_link;
            break;
          case 'parent':
            return $this->menuLinkManager->createInstance($menu_link->getParent());
            break;
          case 'none':
            return null;
            break;
        }
      }
    }

    // Default implementation takes here.
    return parent::getActiveLink($menu_name);
  }
}
