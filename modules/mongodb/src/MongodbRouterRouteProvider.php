<?php

/**
 * @file
 * Contains Drupal\mongodb\MongodbRouterRouteProvider.
 */

namespace Drupal\mongodb;

use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Routing\RouteProvider;
use Drupal\Core\State\StateInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A Route Provider front-end for all Drupal-stored routes.
 */
class MongodbRouterRouteProvider extends RouteProvider {

  /**
   * The name of the table.
   *
   * Warning: this is used by SqlRouterProvider::getCandidateOutlines().
   *
   * @var string
   */
  protected $tableName = 'routing';

  /**
   * @var \Drupal\mongoDb\MongoCollectionFactory $mongo
   */
  protected $mongo;

  /**
   * @param \Drupal\Core\Routing\RouteBuilderInterface $route_builder
   *   The route builder.
   * @param \Drupal\Core\State\State $state
   *   The state.
   * @param string $table
   *   The table in the database to use for matching.
   */
  function __construct(MongoCollectionFactory $mongo, RouteBuilderInterface $route_builder, StateInterface $state, CurrentPathStack $current_path) {
    $this->mongo = $mongo;
    $this->routeBuilder = $route_builder;
    $this->state = $state;
    $this->currentPath = $current_path;
  }

  /**
   * {@inheritdoc}
   */
  public function getRoutesByNames($names, $parameters = []) {

    if (empty($names)) {
      throw new \InvalidArgumentException('You must specify the route names to load');
    }

    $routes_to_load = array_values(array_diff($names, array_keys($this->routes)));

    if ($routes_to_load) {
      $routes = $this->mongo->get($this->tableName)
        ->find(array('_id' => array('$in' => $routes_to_load)));

      foreach ($routes as $name => $route_array) {
        $this->routes[$name] = $this->getRouteFromArray($route_array);
      }
    }

    return array_intersect_key($this->routes, array_flip($names));
  }

  /**
   * {@inheritdoc}
   */
  protected function getRoutesByPath($path) {
    // Filter out each empty value, though allow '0' and 0, which would be
    // filtered out by empty().
    $parts = array_values(array_filter(explode('/', $path), function($value) {
      return $value !== NULL && $value !== '';
    }));

    $ancestors = $this->getCandidateOutlines($parts);

    $routes = $this->mongo->get($this->tableName)
      ->find(array('pattern_outline' => array('$in' => $ancestors)))
      ->sort(array('fit' => -1, '_id' => 1));
    $collection = new RouteCollection();

    foreach ($routes as $name => $route_array) {
      $route = $this->getRouteFromArray($route_array);
      if (preg_match($route->compile()->getRegex(), $path, $matches)) {
        $collection->add($name, $route);
      }
    }

    return $collection;
  }

  /**
   * Creates a Route object from an array.
   *
   * @param array $r
   *
   * @return \Symfony\Component\Routing\Route
   */
  protected function getRouteFromArray(array $r) {
    $r += array(
      'defaults' =>  array(),
      'requirements' => array(),
      'options' => array(),
      'host' => '',
      'schemes' => array(),
      'methods' =>  array('GET', 'POST'),
      'condition' => '',
      'path' => $r['pattern_outline'],
    );
    return new Route($r['path'], $r['defaults'], $r['requirements'], $r['options'], $r['host'], $r['schemes'], $r['methods'], $r['condition']);
  }

}
