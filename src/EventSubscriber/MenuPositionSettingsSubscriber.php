<?php

namespace Drupal\menu_position\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates the menus with rules when the link display setting changes.
 *
 * Cached pages vary by the 'route.menu_active_trails' cache context, which
 * adds only the 'config:system.menu.<menu>' cache tag. The pages therefore do
 * not have the cache tag of the menu_position.settings configuration.
 */
class MenuPositionSettingsSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected MenuLinkManagerInterface $menuLinkManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
    ];
  }

  /**
   * Invalidates the cache tags of the menus with rules.
   *
   * This also applies to changes from drush or a configuration import.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== 'menu_position.settings' || !$event->isChanged('link_display')) {
      return;
    }

    $menu_names = [];
    $child_links_enabled = $event->getConfig()->get('link_display') === 'child';
    foreach ($this->entityTypeManager->getStorage('menu_position_rule')->loadMultiple() as $rule) {
      /** @var \Drupal\menu_position\Entity\MenuPositionRule $rule */
      if ($menu_name = $rule->getMenuName()) {
        $menu_names[$menu_name] = $menu_name;
      }
      $menu_link = $rule->getMenuLink();
      if ($menu_link && $this->menuLinkManager->hasDefinition($menu_link)) {
        $this->menuLinkManager->updateDefinition($menu_link, ['enabled' => $child_links_enabled]);
      }
    }
    if ($menu_names) {
      $this->cacheTagsInvalidator->invalidateTags(Cache::buildTags('config:system.menu', $menu_names, '.'));
    }
  }

}
