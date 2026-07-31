<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_costs\Unit;

use Drupal\ai_costs\Service\AiPricingCatalog;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the shared AI pricing catalogue.
 *
 * @group ai_costs
 */
final class AiPricingCatalogTest extends UnitTestCase {

  /**
   * Tests aliases and cached token pricing.
   */
  public function testEstimateTokens(): void {
    $catalog = $this->createCatalog([
      [
        'provider' => 'grok',
        'model' => 'grok-test',
        'aliases' => ['grok-test-latest'],
        'type' => 'tokens',
        'input_per_million' => 2.0,
        'cached_input_per_million' => 0.2,
        'output_per_million' => 6.0,
      ],
    ]);

    self::assertSame(2.15, $catalog->estimateTokens('xai', 'grok-test-latest', [
      'input_tokens' => 1_000_000,
      'cached_input_tokens' => 250_000,
      'output_tokens' => 100_000,
    ]));
  }

  /**
   * Tests strict pricing validation.
   */
  public function testNormalizeRejectsNegativeRates(): void {
    $catalog = $this->createCatalog([]);
    $this->expectException(\UnexpectedValueException::class);
    $catalog->normalizeJson('[{"provider":"openai","model":"bad","type":"tokens","input_per_million":-1,"output_per_million":1}]');
  }

  /**
   * Tests that the newest active price is selected regardless of row order.
   */
  public function testFindSelectsNewestActivePrice(): void {
    $catalog = $this->createCatalog([
      [
        'provider' => 'openai',
        'model' => 'test-model',
        'type' => 'tokens',
        'effective_from' => '2025-01-01',
        'input_per_million' => 1.0,
        'output_per_million' => 2.0,
      ],
      [
        'provider' => 'openai',
        'model' => 'test-model',
        'type' => 'tokens',
        'effective_from' => '2026-01-01',
        'input_per_million' => 3.0,
        'output_per_million' => 4.0,
      ],
    ]);

    $row = $catalog->find('openai', 'test-model', '2026-07-01');
    self::assertSame(3.0, $row['input_per_million']);
  }

  /**
   * Tests that operation-specific token pricing is used throughout estimation.
   */
  public function testEstimatePreservesOperationSpecificTokenRow(): void {
    $catalog = $this->createCatalog([
      [
        'provider' => 'openai',
        'model' => 'test-model',
        'type' => 'tokens',
        'input_per_million' => 1.0,
        'output_per_million' => 1.0,
      ],
      [
        'provider' => 'openai',
        'model' => 'test-model',
        'operation' => 'chat',
        'type' => 'tokens',
        'input_per_million' => 2.0,
        'output_per_million' => 4.0,
      ],
    ]);

    self::assertSame(6.0, $catalog->estimate(
      'openai',
      'chat',
      'test-model',
      tokens: [
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
      ],
    ));
  }

  /**
   * Tests strict calendar date and date-range validation.
   */
  public function testNormalizeRejectsInvalidDateRange(): void {
    $catalog = $this->createCatalog([]);
    $this->expectException(\UnexpectedValueException::class);
    $catalog->normalizeJson('[{"provider":"openai","model":"bad","type":"tokens","input_per_million":1,"output_per_million":1,"effective_from":"2026-02-30","effective_until":"2026-01-01"}]');
  }

  /**
   * Creates a catalogue with configured pricing.
   */
  private function createCatalog(array $pricing): AiPricingCatalog {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('model_pricing')
      ->willReturn($pricing);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('ai_costs.settings')
      ->willReturn($config);

    return new AiPricingCatalog(
      $factory,
      $this->createMock(ExtensionPathResolver::class),
    );
  }

}
