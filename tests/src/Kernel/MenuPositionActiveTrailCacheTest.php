<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_position\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\menu_position\Entity\MenuPositionRule;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the active trail cache between requests.
 *
 * @group menu_position
 */
#[Group('menu_position')]
#[RunTestsInSeparateProcesses]
class MenuPositionActiveTrailCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'language',
    'link',
    'menu_link_content',
    'menu_position',
  ];

  /**
   * The anonymous user.
   */
  protected UserInterface $anonymous;

  /**
   * An authenticated user.
   */
  protected UserInterface $authenticated;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['system', 'language', 'menu_position']);
    $this->config('menu_position.settings')->set('link_display', 'child')->save();

    $this->anonymous = User::create(['uid' => 0, 'name' => '']);
    $this->anonymous->save();
    $this->authenticated = User::create(['name' => 'visitor']);
    $this->authenticated->save();

    // The trail of this menu changes only with the path.
    $this->createRule('by_path', 'main', [
      'request_path' => [
        'id' => 'request_path',
        'pages' => '/user/login',
        'negate' => FALSE,
      ],
    ]);
    // The trail of this menu changes with the user.
    $this->createRule('by_role', 'footer', [
      'user_role' => [
        'id' => 'user_role',
        'roles' => ['anonymous' => 'anonymous'],
        'negate' => FALSE,
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
      ],
    ]);
    // A router rebuild also rebuilds the menu links and clears the menu cache.
    // Do it now, not at the end of a request.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests that the cache keeps a trail only if the cache ID controls its rules.
   */
  public function testPersistedTrails(): void {
    $request = $this->startRequest($this->anonymous);
    $active_trail = $this->container->get('menu.active_trail');
    $this->assertArrayHasKey('menu_position_link:by_path', $active_trail->getActiveTrailIds('main'));
    $this->assertArrayHasKey('menu_position_link:by_role', $active_trail->getActiveTrailIds('footer'));
    $cid = (fn () => $this->getCid())->call($active_trail);
    $this->endRequest($request);

    $cache = $this->container->get('cache.menu')->get($cid);
    $this->assertNotFalse($cache, 'The active trail was persisted when the request ended.');
    $this->assertArrayHasKey('main', $cache->data);
    $this->assertArrayNotHasKey('footer', $cache->data, 'A trail that varies by role was not persisted.');

    // A different user on the same route does not see the anonymous trail.
    $request = $this->startRequest($this->authenticated);
    $active_trail = $this->container->get('menu.active_trail');
    $this->assertArrayHasKey('menu_position_link:by_path', $active_trail->getActiveTrailIds('main'));
    $this->assertArrayNotHasKey('menu_position_link:by_role', $active_trail->getActiveTrailIds('footer'));
    $this->endRequest($request);

    // A settings change without a router rebuild removes the trails from the
    // cache.
    $this->config('menu_position.settings')->set('link_display', 'none')->save();
    $this->assertFalse($this->container->get('cache.menu')->get($cid));
    $request = $this->startRequest($this->anonymous);
    $this->assertArrayNotHasKey('menu_position_link:by_path', $this->container->get('menu.active_trail')->getActiveTrailIds('main'));
    $this->endRequest($request);
  }

  /**
   * Tests that a menu change removes the trails from the cache.
   */
  public function testMenuChangeInvalidatesPersistedTrails(): void {
    $parent = MenuLinkContent::create([
      'title' => 'Parent',
      'link' => ['uri' => 'internal:/parent'],
      'menu_name' => 'main',
    ]);
    $parent->save();

    $request = $this->startRequest($this->anonymous);
    $this->assertArrayNotHasKey($parent->getPluginId(), $this->container->get('menu.active_trail')->getActiveTrailIds('main'));
    $this->endRequest($request);

    // The menu UI moves a link without a router rebuild.
    $this->container->get('plugin.manager.menu.link')->updateDefinition('menu_position_link:by_path', ['parent' => $parent->getPluginId()]);

    $request = $this->startRequest($this->anonymous);
    $this->assertArrayHasKey($parent->getPluginId(), $this->container->get('menu.active_trail')->getActiveTrailIds('main'));
    $this->endRequest($request);
  }

  /**
   * Tests that the cache keeps a different trail for each language.
   */
  public function testPersistedTrailsPerLanguage(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->createRule('by_language', 'tools', [
      'language' => [
        'id' => 'language',
        'langcodes' => ['fr' => 'fr'],
        'negate' => FALSE,
        'context_mapping' => ['language' => '@language.current_language_context:language_interface'],
      ],
    ]);
    $this->container->get('router.builder')->rebuild();

    // The French and the English login pages have the same route.
    $request = $this->startRequest($this->anonymous, '/fr/user/login');
    $active_trail = $this->container->get('menu.active_trail');
    $this->assertArrayHasKey('menu_position_link:by_language', $active_trail->getActiveTrailIds('tools'));
    $cid = (fn () => $this->getCid())->call($active_trail);
    $this->endRequest($request);
    $cache = $this->container->get('cache.menu')->get($cid);
    $this->assertNotFalse($cache, 'A trail that varies by language was persisted.');
    $this->assertArrayHasKey('tools', $cache->data);

    $request = $this->startRequest($this->anonymous);
    $this->assertArrayNotHasKey('menu_position_link:by_language', $this->container->get('menu.active_trail')->getActiveTrailIds('tools'));
    $this->endRequest($request);
  }

  /**
   * Creates a rule.
   */
  protected function createRule(string $id, string $menu_name, array $conditions): void {
    MenuPositionRule::create([
      'id' => $id,
      'label' => $id,
      'enabled' => TRUE,
      'conditions' => $conditions,
      'menu_name' => $menu_name,
      'parent' => '',
      'menu_link' => 'menu_position_link:' . $id,
      'weight' => 0,
    ])->save();
  }

  /**
   * Starts a request to the login page for the user.
   */
  protected function startRequest(UserInterface $account, string $path = '/user/login'): Request {
    // Each request uses new instances of the services that keep request data.
    foreach (['menu_position.menu.active_trail', 'context.repository'] as $id) {
      if ($this->container->initialized($id)) {
        $this->container->set($id, NULL);
      }
    }
    $this->container->get('entity_type.manager')->getStorage('menu_position_rule')->resetCache();
    $this->container->get('current_user')->setAccount($account);

    $request = Request::create($path);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'user.login');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName('user.login'));
    $request->attributes->set('_raw_variables', new InputBag());
    $this->container->get('request_stack')->push($request);
    // The router keeps the path without the language prefix.
    $this->container->get('path.current')->setPath('/user/login', $request);
    // Find the language of this request. The language module does this on
    // multilingual sites.
    $negotiator = $this->container->get('language_negotiator');
    $negotiator->setCurrentUser($account);
    $language_manager = $this->container->get('language_manager');
    $language_manager->setNegotiator($negotiator);
    $language_manager->reset();
    return $request;
  }

  /**
   * Ends a request in the same way as the kernel.
   */
  protected function endRequest(Request $request): void {
    $this->container->get('kernel')->terminate($request, new Response());
    $this->container->get('request_stack')->pop();
  }

}
