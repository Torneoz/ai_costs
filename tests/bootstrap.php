<?php

/**
 * @file
 * PHPUnit bootstrap for standalone module development.
 */

declare(strict_types=1);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4(
  'Drupal\\ai_costs\\',
  dirname(__DIR__) . '/src',
);
$loader->addPsr4(
  'Drupal\\Tests\\',
  dirname(__DIR__) . '/vendor/drupal/core/tests/Drupal/Tests',
);
$loader->addPsr4(
  'Drupal\\TestTools\\',
  dirname(__DIR__) . '/vendor/drupal/core/tests/Drupal/TestTools',
);
