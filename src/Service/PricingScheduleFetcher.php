<?php

declare(strict_types=1);

namespace Drupal\ai_costs\Service;

use GuzzleHttp\ClientInterface;

/**
 * Downloads the module-maintained provider pricing schedule.
 */
final class PricingScheduleFetcher {

  /**
   * The trusted machine-readable pricing schedule.
   */
  public const PRICING_URL = 'https://raw.githubusercontent.com/Torneoz/ai_costs/main/data/pricing.json';

  /**
   * Maximum accepted pricing response size.
   */
  private const MAX_RESPONSE_BYTES = 262144;

  /**
   * Constructs the pricing schedule fetcher.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly AiPricingCatalog $pricingCatalog,
  ) {}

  /**
   * Downloads and validates the latest maintained pricing schedule.
   *
   * @return array{json: string, source: string, checked_at: string, hash: string, rows: int}
   *   Normalized pricing data and its provenance.
   */
  public function fetch(): array {
    $response = $this->httpClient->request('GET', self::PRICING_URL, [
      'allow_redirects' => FALSE,
      'connect_timeout' => 5,
      'timeout' => 10,
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'Drupal AI Costs pricing updater',
      ],
    ]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException(sprintf('The pricing server returned HTTP %d.', $response->getStatusCode()));
    }

    $contentLength = $response->getHeaderLine('Content-Length');
    if ($contentLength !== '' && (int) $contentLength > self::MAX_RESPONSE_BYTES) {
      throw new \RuntimeException('The downloaded pricing schedule is too large.');
    }
    $json = (string) $response->getBody();
    if ($json === '' || strlen($json) > self::MAX_RESPONSE_BYTES) {
      throw new \RuntimeException('The downloaded pricing schedule is empty or too large.');
    }

    $normalized = $this->pricingCatalog->normalizeJson($json);
    $rows = json_decode($normalized, TRUE, 512, JSON_THROW_ON_ERROR);
    if ($rows === []) {
      throw new \RuntimeException('The downloaded pricing schedule contains no rows.');
    }

    return [
      'json' => $normalized,
      'source' => self::PRICING_URL,
      'checked_at' => gmdate(DATE_ATOM),
      'hash' => hash('sha256', $normalized),
      'rows' => count($rows),
    ];
  }

}
