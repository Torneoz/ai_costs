<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_costs\Unit;

use Drupal\ai_costs\Service\AiPricingCatalog;
use Drupal\ai_costs\Service\PricingScheduleFetcher;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests retrieval of the maintained pricing schedule.
 *
 * @group ai_costs
 */
final class PricingScheduleFetcherTest extends TestCase {

  /**
   * Tests that valid remote pricing is normalized and described.
   */
  public function testFetchesAndValidatesPricing(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::once())
      ->method('request')
      ->with(
        'GET',
        PricingScheduleFetcher::PRICING_URL,
        self::callback(static fn(array $options): bool =>
          $options['allow_redirects'] === FALSE
          && $options['connect_timeout'] === 5
          && $options['timeout'] === 10
        ),
      )
      ->willReturn(new Response(200, [], '[{"provider":"openai","model":"test","type":"tokens","input_per_million":1,"output_per_million":2}]'));

    $schedule = $this->createFetcher($client)->fetch();

    self::assertSame(PricingScheduleFetcher::PRICING_URL, $schedule['source']);
    self::assertSame(1, $schedule['rows']);
    self::assertSame(hash('sha256', $schedule['json']), $schedule['hash']);
    self::assertStringContainsString("\n", $schedule['json']);
  }

  /**
   * Tests that malformed pricing is rejected.
   */
  public function testRejectsInvalidPricing(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(200, [], '{"unexpected":"object"}'));

    $this->expectException(\UnexpectedValueException::class);
    $this->createFetcher($client)->fetch();
  }

  /**
   * Tests that redirects are rejected.
   */
  public function testRejectsUnexpectedResponse(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(302, ['Location' => 'https://example.com/pricing.json']));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('HTTP 302');
    $this->createFetcher($client)->fetch();
  }

  /**
   * Tests that oversized responses are rejected.
   */
  public function testRejectsOversizedResponse(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(200, ['Content-Length' => '262145'], '[]'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('too large');
    $this->createFetcher($client)->fetch();
  }

  /**
   * Tests that an empty schedule cannot replace usable pricing.
   */
  public function testRejectsEmptySchedule(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(200, [], '[]'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('no rows');
    $this->createFetcher($client)->fetch();
  }

  /**
   * Creates a fetcher with the real pricing normalizer.
   */
  private function createFetcher(ClientInterface $client): PricingScheduleFetcher {
    return new PricingScheduleFetcher(
      $client,
      new AiPricingCatalog(
        $this->createMock(ConfigFactoryInterface::class),
        $this->createMock(ExtensionPathResolver::class),
      ),
    );
  }

}
