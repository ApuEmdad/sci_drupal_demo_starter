<?php

declare(strict_types=1);

namespace Drupal\Tests\tmgmt_local\Unit;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\tmgmt_local\Menu\TMGMTLocalBreadcrumbBuilder;

/**
 * @coversDefaultClass \Drupal\tmgmt_local\Menu\TMGMTLocalBreadcrumbBuilder
 * @group tmgmt_local
 */
class TMGMTLocalBreadcrumbBuilderTest extends UnitTestCase {

  /**
   * The TMGMTLocalBreadcrumbBuilder instance. This is the system under test.
   */
  protected TMGMTLocalBreadcrumbBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->builder = new TMGMTLocalBreadcrumbBuilder();
  }

  /**
   * Tests that the applies() method works when there is no route match.
   *
   * @covers ::applies
   */
  public function testAppliesWithoutMatchedRoute(): void {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn(NULL);
    $this->assertFalse($this->builder->applies($routeMatch), 'The applies() method returns FALSE when there is no route match.');
  }

}
