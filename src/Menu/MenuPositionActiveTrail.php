<?php

namespace Drupal\menu_position\Menu;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheCollector;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Menu Position active trail.
 *
 * Decorates the MenuActiveTrail class.
 */
class MenuPositionActiveTrail extends CacheCollector implements MenuActiveTrailInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Menu position settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * The menu link plugin manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $menuLinkManager;

  /**
   * The route match object for the current page.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The decorated MenuActiveTrail service.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected $inner;

  /**
   * Constructs a \Drupal\Core\Menu\MenuActiveTrail object.
   *
   * @param \Drupal\Core\Menu\MenuActiveTrailInterface $menu_active_trail
   *   The menu link plugin manager.
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   *   The menu link plugin manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A route match object for finding the active link.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(
    #[AutowireDecorated]
    MenuActiveTrailInterface $menu_active_trail,
    MenuLinkManagerInterface $menu_link_manager,
    RouteMatchInterface $route_match,
    #[Autowire(service: 'cache.menu')]
    CacheBackendInterface $cache,
    #[Autowire(service: 'lock')]
    LockBackendInterface $lock,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->inner = $menu_active_trail;
    $this->menuLinkManager = $menu_link_manager;
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->settings = $config_factory->get('menu_position.settings');
    parent::__construct(NULL, $cache, $lock);
  }

  /**
   * {@inheritdoc}
   *
   * @see ::getActiveTrailIds()
   */
  protected function getCid(): string {
    if (!isset($this->cid)) {
      $route_parameters = $this->routeMatch->getRawParameters()->all();
      ksort($route_parameters);
      $this->cid = 'active-trail:route:' . $this->routeMatch->getRouteName() . ':route_parameters:' . serialize($route_parameters);
    }

    return $this->cid;
  }

  /**
   * {@inheritdoc}
   *
   * @see ::getActiveTrailIds()
   */
  protected function resolveCacheMiss($menu_name): mixed {
    $this->storage[$menu_name] = $this->doGetActiveTrailIds($menu_name);
    $this->tags[] = 'config:system.menu.' . $menu_name;
    $this->persist($menu_name);

    return $this->storage[$menu_name];
  }

  /**
   * {@inheritdoc}
   *
   * This implementation caches all active trail IDs per route match for *all*
   * menus whose active trails are calculated on that page. This ensures 1 cache
   * get for all active trails per page load, rather than N.
   *
   * It uses the cache collector pattern to do this.
   *
   * @see ::get()
   * @see \Drupal\Core\Cache\CacheCollectorInterface
   * @see CacheCollector
   */
  public function getActiveTrailIds($menu_name): array {
    return $this->get($menu_name);
  }

  /**
   * Helper method for ::getActiveTrailIds().
   */
  protected function doGetActiveTrailIds($menu_name) {
    // Parent ids; used both as key and value to ensure uniqueness.
    // We always want all the top-level links with parent == ''.
    $active_trail = ['' => ''];

    // If a link in the given menu indeed matches the route, then use it to
    // complete the active trail.
    if ($active_link = $this->getActiveLink($menu_name)) {
      if ($parents = $this->menuLinkManager->getParentIds($active_link->getPluginId())) {
        $active_trail = $parents + $active_trail;
      }
    }

    return $active_trail;
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveLink($menu_name = NULL): ?MenuLinkInterface {
    static $cache = [];
    if (isset($cache[$menu_name])) {
      return $cache[$menu_name];
    }
    // Get all the rules.
    $query = $this->entityTypeManager->getStorage('menu_position_rule')->getQuery();

    // Filter on the menu name if there is one.
    if (isset($menu_name)) {
      $query->condition('menu_name', $menu_name);
    }

    $results = $query->sort('weight')->accessCheck(FALSE)->execute();
    $rules = $this->entityTypeManager->getStorage('menu_position_rule')->loadMultiple($results);

    // Iterate over the rules.
    foreach ($rules as $rule) {
      // This rule is active.
      if ($rule->isActive()) {
        $menu_link = $this->menuLinkManager->createInstance($rule->getMenuLink());
        $active_menu_link = NULL;
        switch ($this->settings->get('link_display')) {
          case 'child':
            // Set this menu link to active.
            $active_menu_link = $menu_link;
            break;

          case 'parent':
            try {
              $active_menu_link = $this->menuLinkManager->createInstance($menu_link->getParent());
            }
            catch (PluginException $e) {
              $active_menu_link = NULL;
            }
            break;

          case 'none':
            $active_menu_link = NULL;
            break;
        }

        $cache[$menu_name] = $active_menu_link;
        return $active_menu_link;
      }
    }

    // Default implementation takes here.
    return $this->inner->getActiveLink($menu_name);
  }

}
