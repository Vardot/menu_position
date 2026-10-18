<?php

namespace Drupal\menu_position\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\menu_position\Plugin\Menu\MenuPositionLink;

/**
 * Hook implementations for menu_position.
 */
class MenuPositionHooks {

  /**
   * Implements hook_form_FORM_ID_alter() for menu_edit_form().
   */
  #[Hook('form_menu_edit_form_alter')]
  public function formMenuEditFormAlter(array &$form, FormStateInterface $form_state): void {
    foreach (Element::children($form['links']['links']) as $key) {
      $menu_link = $form['links']['links'][$key];
      if (isset($menu_link['#item']->link) && $menu_link['#item']->link instanceof MenuPositionLink) {
        $form['links']['links'][$key]['enabled']['#disabled'] = TRUE;
        $form['links']['links'][$key]['enabled']['#default_value'] = TRUE;
      }
    }
  }

}
