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
