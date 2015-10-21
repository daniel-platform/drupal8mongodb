<?php

/**
 * @file
 * Definition of Drupal\mongodb\MongoKeyValueFactory.
 */

namespace Drupal\mongodb;

use Drupal\Core\State\State;
use Drupal\Core\Routing\MatcherDumperInterface;
use Symfony\Component\Routing\RouteCollection;

class MongodbRouterDumper implements MatcherDumperInterface {

  const ROUTE_COLLECTION = 'routing';

  /**
   * @var \Drupal\mongoDb\MongoCollectionFactory $mongo
   */
  protected $mongo;

  /**
   * The routes to be dumped.
   *
   * @var \Symfony\Component\Routing\RouteCollection
   */
  protected $routes;

  /**
   * The state.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * @param \Drupal\mongoDb\MongoCollectionFactory $mongo
   */
  function __construct(MongoCollectionFactory $mongo, State $state) {
    $this->mongo = $mongo;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function addRoutes(RouteCollection $routes) {
    if (empty($this->routes)) {
      $this->routes = $routes;
    }
    else {
      $this->routes->addCollection($routes);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRoutes() {
    return $this->routes;
  }

  /**
   * {@inheritdoc}
   */
  public function dump(array $options = []) {
    $collection = $this->mongo->get(static::ROUTE_COLLECTION);
    // If there are no new routes, just delete any previously existing of this
    // provider.
    $provider = isset($options['provider']) ? $options['provider'] : '';
    if (empty($this->routes) || !count($this->routes)) {
      $collection->remove(array('provider' => $provider));
    }
    // Convert all of the routes into database records.
    else {
      $names = array();
      $masks = array_flip($this->state->get('routing.menu_masks.' . static::ROUTE_COLLECTION, array()));
      foreach ($this->routes as $name => $route) {
        $name = (string) $name;
        $names[] = $name;
        /** @var \Symfony\Component\Routing\Route $route */
        $route->setOption('compiler_class', '\Drupal\Core\Routing\RouteCompiler');
        /** @var \Drupal\Core\Routing\CompiledRoute $compiled */
        $compiled = $route->compile();
        $masks[$compiled->getFit()] = 1;
        $options = $route->getOptions();
        unset($options['compiler_class']);
        $pattern_outline = $compiled->getPatternOutline();
        $route_array = array_filter(array(
          'path' => $route->getPath(),
          'defaults' =>  $route->getDefaults(),
          'requirements' => $route->getRequirements(),
          'options' => $options,
          'host' => $route->getHost(),
          'schemes' => $route->getSchemes(),
          'methods' =>  $route->getMethods(),
          'condition' => $route->getCondition(),
        ));
        unset($route_array['requirements']['_method']);
        if (isset($route_array['methods']) && $route_array['methods'] === array('GET', 'POST')) {
          unset($route_array['methods']);
        }
        if ($route_array['path'] === $pattern_outline) {
          unset($route_array['path']);
        }
        $collection->update(array('_id' => $name), array(
          '_id' => $name,
          'provider' => $provider,
          'fit' => $compiled->getFit(),
          'pattern_outline' => $pattern_outline,
        ) + $route_array, array('upsert' => TRUE));
      }
      // Sort the masks so they are in order of descending fit.
      $masks = array_keys($masks);
      rsort($masks);
      $this->state->set('routing.menu_masks.' . static::ROUTE_COLLECTION, $masks);
      $collection->remove(array(
        'provider' => $provider,
        '_id' => array('$nin' => $names),
      ));
    }
    // The dumper is reused for multiple providers, so reset the queued routes.
    $this->routes = NULL;
  }

}
