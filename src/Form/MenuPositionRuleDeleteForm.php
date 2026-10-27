<?php

namespace Drupal\menu_position\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;

/**
 * Builds the form to delete a Example.
 */
class MenuPositionRuleDeleteForm extends EntityConfirmFormBase {

  use AutowireTrait;

  /**
   * The Menu Parent Form Selector.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $menuLinkManager;

  /**
   * The menu position rule delete form constructor.
   *
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   *   The menu link manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    MenuLinkManagerInterface $menu_link_manager,
    MessengerInterface $messenger,
  ) {

    $this->menuLinkManager = $menu_link_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the %name rule?', ['%name' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.menu_position_rule.order_form');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->menuLinkManager->removeDefinition($this->entity->getMenuLink());
    $this->entity->delete();
    $this->messenger->addMessage($this->t('The %label rule has been deleted.', ['%label' => $this->entity->getLabel()]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
