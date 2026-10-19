<?php

namespace Drupal\menu_position\Menu;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheCollector;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
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
   * Cache contexts that the cache ID fully controls.
   *
   * The cache ID contains the route, the raw route parameters and the current
   * language of each language type. The module keeps a trail in the cache only
   * if its rules use no other cache contexts. This makes sure that each user
   * sees the correct trail.
   *
   * The request_path condition uses the 'url.path' context. The cache ID also
   * controls this context. The condition compares the internal path and the
   * alias of that path in the current URL language. The route and its
   * parameters set the internal path.
   *
   * @see ::getCid()
   * @see ::isPersistable()
   */
  protected const PERSISTABLE_CACHE_CONTEXTS = ['route', 'url.path', 'languages'];

  /**
   * Cache contexts in the persistable list that the cache ID does not control.
   *
   * The 'route.menu_active_trails' context reads its value from this service.
   * The 'url.path.is_front' context also changes with the front page setting.
   *
   * @see ::isPersistable()
   */
  protected const EXCLUDED_CACHE_CONTEXTS = ['route.menu_active_trails', 'url.path.is_front'];

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
    protected LanguageManagerInterface $languageManager,
  ) {
    $this->inner = $menu_active_trail;
    $this->menuLinkManager = $menu_link_manager;
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->settings = $config_factory->get('menu_position.settings');
    // The cached trails change when a rule or the link display setting
    // changes.
    $tags = Cache::mergeTags(
      $entity_type_manager->getDefinition('menu_position_rule')->getListCacheTags(),
      $this->settings->getCacheTags(),
    );
    parent::__construct(NULL, $cache, $lock, $tags);
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
      $langcodes = [];
      foreach ($this->languageManager->getLanguageTypes() as $type) {
        $langcodes[] = $this->languageManager->getCurrentLanguage($type)->getId();
      }
      // The decorated service uses the same cache bin. Use a different prefix,
      // so that each service reads only its own trails.
      $this->cid = 'menu_position:active-trail:route:' . $this->routeMatch->getRouteName() . ':route_parameters:' . serialize($route_parameters) . ':languages:' . implode(',', $langcodes);
    }

    return $this->cid;
  }

  /**
   * {@inheritdoc}
   *
   * @see ::getActiveTrailIds()
   */
  protected function resolveCacheMiss($menu_name): mixed {
    $cacheability = new CacheableMetadata();
    $this->storage[$menu_name] = $this->doGetActiveTrailIds($menu_name, $cacheability);
    // Keep the trail only for the current request if it changes with other
    // data, for example the user role or the theme.
    if ($this->isPersistable($cacheability)) {
      $this->tags = Cache::mergeTags($this->tags, ['config:system.menu.' . $menu_name], $cacheability->getCacheTags());
      $this->persist($menu_name);
    }

    return $this->storage[$menu_name];
  }

  /**
   * Checks if a trail with this cacheability can stay in the cache.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $cacheability
   *   The cacheability of the rules that the module examined to calculate the
   *   trail.
   *
   * @return bool
   *   TRUE if the cache ID controls all cache contexts of the trail.
   */
  protected function isPersistable(CacheableDependencyInterface $cacheability): bool {
    if ($cacheability->getCacheMaxAge() !== Cache::PERMANENT) {
      return FALSE;
    }
    foreach ($cacheability->getCacheContexts() as $context) {
      if (!static::matchesCacheContexts($context, static::PERSISTABLE_CACHE_CONTEXTS) || static::matchesCacheContexts($context, static::EXCLUDED_CACHE_CONTEXTS)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Checks if a cache context is in a list of cache context IDs.
   *
   * A child context also matches, for example 'route.name' for 'route'. A
   * context with a parameter also matches, for example 'languages:language_url'
   * for 'languages'.
   */
  protected static function matchesCacheContexts(string $context, array $contexts): bool {
    foreach ($contexts as $match) {
      if ($context === $match || str_starts_with($context, $match . '.') || str_starts_with($context, $match . ':')) {
        return TRUE;
      }
    }
    return FALSE;
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
   *
   * @param string|null $menu_name
   *   The menu name.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface|null $cacheability
   *   (optional) Collects the cacheability of the trail.
   */
  protected function doGetActiveTrailIds(?string $menu_name, ?RefinableCacheableDependencyInterface $cacheability = NULL): array {
    // Parent ids; used both as key and value to ensure uniqueness.
    // We always want all the top-level links with parent == ''.
    $active_trail = ['' => ''];

    // If a link in the given menu indeed matches the route, then use it to
    // complete the active trail.
    if ($active_link = $this->doGetActiveLink($menu_name, $cacheability ?? new CacheableMetadata())) {
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
    return $this->doGetActiveLink($menu_name, new CacheableMetadata());
  }

  /**
   * Helper method for ::getActiveLink().
   *
   * @param string|null $menu_name
   *   The menu name.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   Collects the cacheability of the rules that the module examines to find
   *   the link.
   */
  protected function doGetActiveLink(?string $menu_name, RefinableCacheableDependencyInterface $cacheability): ?MenuLinkInterface {
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
      if ($rule->isActive($cacheability)) {
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

        return $active_menu_link;
      }
    }

    // Default implementation takes here.
    return $this->inner->getActiveLink($menu_name);
  }

}
