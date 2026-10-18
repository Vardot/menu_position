<?php

namespace Drupal\menu_position\Plugin\Menu;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines menu links provided by menu position rules.
 *
 * @see \Drupal\menu_position\Plugin\Derivative\MenuPositionLink
 */
class MenuPositionLink extends MenuLinkBase implements ContainerFactoryPluginInterface {

  /**
   * Menu position settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $config_factory,
    protected RouteMatchInterface $routeMatch,
    protected RequestStack $requestStack,
    protected AdminContext $adminContext,
    protected TitleResolverInterface $titleResolver,
    protected RendererInterface $renderer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->settings = $config_factory->get('menu_position.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('router.admin_context'),
      $container->get('title_resolver'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected $overrideAllowed = [
    'menu_name' => 1,
    'parent' => 1,
    'weight' => 1,
  ];

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    // When we're in an admin route we want to display the name of the menu
    // position rule.
    // @todo Ensure this translates properly when using configuration
    //   translation.
    if ($this->adminContext->isAdminRoute()) {
      return $this->pluginDefinition['title'] ?? "";
    }
    // When we're on a non-admin route we want to display the page title.
    else {
      $route = $this->routeMatch->getRouteObject();
      if ($route) {
        $title = $this->titleResolver->getTitle($this->requestStack->getCurrentRequest(), $route);
        if (is_array($title)) {
          $title = $this->renderer->renderInIsolation($title);
        }
      }

      // There is no current route (for example, in a Drush context).
      return $title ?? $this->pluginDefinition['title'] ?? '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->getPluginDefinition()['description'];
  }

  /**
   * {@inheritdoc}
   */
  public function updateLink(array $new_definition_values, $persist): array {
    // Filter the list of updates to only those that are allowed.
    $overrides = array_intersect_key($new_definition_values, $this->overrideAllowed);
    // Update the definition.
    $this->pluginDefinition = $overrides + $this->getPluginDefinition();

    return $this->pluginDefinition;
  }

  /**
   * {@inheritdoc}
   */
  public function isDeletable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    if ($this->settings->get('link_display') === 'child') {
      // Only insert into menu tree if the rule matches.
      $rule = $this->entityTypeManager->getStorage('menu_position_rule')
        ->load($this->pluginDefinition['metadata']['entity_id']);
      return $rule->isActive();
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteLink() {
    // Noop.
  }

  /**
   * {@inheritdoc}
   */
  public function getEditRoute(): ?Url {
    $storage = $this->entityTypeManager->getStorage('menu_position_rule');
    $entity_id = $this->pluginDefinition['metadata']['entity_id'];
    $entity = $storage->load($entity_id);
    return $entity->toUrl();
  }

}
