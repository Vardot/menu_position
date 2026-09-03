<?php

/**
 * @file
 * Post update functions for Menu Position.
 */

/**
 * Implements hook_post_update_NAME().
 */
function menu_position_post_update_clear_cache_for_active_trail() {
  // Trigger a cache rebuild for the new config.factory argument added to
  // MenuPositionServiceProvider.
}

/**
 * Implements hook_post_update_NAME().
 */
function menu_position_post_update_clear_rebuild_for_entity_query_removal() {
  // Trigger a cache rebuild for the removal entity.query argument since it's
  // deprecated in Drupal 9.
  // @see https://www.drupal.org/node/2849874
}

/**
 * Synchronizes menu link definitions with menu position rule configuration.
 */
function menu_position_post_update_sync_menu_link_definitions() {
  $menu_link_manager = Drupal::service('plugin.manager.menu.link');
  $menu_link_manager->rebuild();

  $rules = Drupal::entityTypeManager()
    ->getStorage('menu_position_rule')
    ->loadMultiple();
  foreach ($rules as $rule) {
    /** @var \Drupal\menu_position\MenuPositionRuleInterface $rule */
    $menu_link = $rule->getMenuLink();
    if ($menu_link && $menu_link_manager->hasDefinition($menu_link)) {
      $menu_link_manager->updateDefinition($menu_link, [
        'menu_name' => $rule->getMenuName(),
        'parent' => $rule->getParent(),
      ]);
    }
  }
}
