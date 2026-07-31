<?php

declare(strict_types=1);

namespace Drupal\ai_costs\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;

/**
 * Provides provider-neutral AI model pricing and cost estimates.
 */
final class AiPricingCatalog {

  /**
   * Supported non-negative numeric pricing fields.
   */
  private const NUMERIC_FIELDS = [
    'input_per_million',
    'cached_input_per_million',
    'cache_write_5m_per_million',
    'cache_write_1h_per_million',
    'output_per_million',
    'long_context_threshold',
    'long_input_per_million',
    'long_cached_input_per_million',
    'long_output_per_million',
    'input_per_image',
    'output_per_image_1k',
    'output_per_image_2k',
    'output_per_second_480p',
    'output_per_second_720p',
    'output_per_second_1080p',
    'per_hour',
    'per_million_characters',
  ];

  /**
   * Constructs the pricing catalogue.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ExtensionPathResolver $extensionPathResolver,
  ) {}

  /**
   * Returns configured pricing, falling back to the packaged catalogue.
   */
  public function getPricing(): array {
    $configured = $this->configFactory
      ->get('ai_costs.settings')
      ->get('model_pricing');
    if (is_array($configured) && $configured !== []) {
      return $this->normalize($configured);
    }
    return $this->getPackagedPricing();
  }

  /**
   * Returns the packaged pricing rows.
   */
  public function getPackagedPricing(): array {
    $modulePath = $this->extensionPathResolver->getPath('module', 'ai_costs');
    $path = DRUPAL_ROOT . '/' . $modulePath . '/data/pricing.json';
    $json = file_get_contents($path);
    if (!is_string($json) || trim($json) === '') {
      throw new \RuntimeException('The packaged AI pricing catalogue could not be read.');
    }
    $rows = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows) || !array_is_list($rows)) {
      throw new \RuntimeException('The packaged AI pricing catalogue is invalid.');
    }
    return $this->normalize($rows);
  }

  /**
   * Validates and normalizes pricing JSON.
   */
  public function normalizeJson(string $json): string {
    $rows = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows) || !array_is_list($rows)) {
      throw new \UnexpectedValueException('Pricing data must be a JSON array.');
    }
    return (string) json_encode(
      $this->normalize($rows),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
  }

  /**
   * Estimates token usage in USD.
   */
  public function estimateTokens(string $provider, string $model, array $usage): ?float {
    $row = $this->find($provider, $model);
    if ($row === NULL || ($row['type'] ?? '') !== 'tokens') {
      return NULL;
    }
    return $this->estimateTokenRow($row, $usage);
  }

  /**
   * Estimates token usage using a resolved pricing row.
   */
  private function estimateTokenRow(array $row, array $usage): float {
    $input = max(0, (int) ($usage['input_tokens'] ?? $usage['input'] ?? 0));
    $output = max(0, (int) ($usage['output_tokens'] ?? $usage['output'] ?? 0));
    $cached = min($input, max(0, (int) ($usage['cached_input_tokens'] ?? $usage['cached'] ?? 0)));
    $cacheWrite5m = min(
      $input - $cached,
      max(0, (int) ($usage['cache_creation_5m_input_tokens'] ?? 0)),
    );
    $cacheWrite1h = min(
      $input - $cached - $cacheWrite5m,
      max(0, (int) ($usage['cache_creation_1h_input_tokens'] ?? 0)),
    );
    $uncached = max(0, $input - $cached - $cacheWrite5m - $cacheWrite1h);
    $longContext = isset($row['long_context_threshold'])
      && $input >= (int) $row['long_context_threshold'];
    $inputRate = (float) ($longContext
      ? ($row['long_input_per_million'] ?? $row['input_per_million'])
      : $row['input_per_million']);
    $cachedRate = (float) ($longContext
      ? ($row['long_cached_input_per_million'] ?? $row['cached_input_per_million'] ?? $inputRate)
      : ($row['cached_input_per_million'] ?? $inputRate));
    $outputRate = (float) ($longContext
      ? ($row['long_output_per_million'] ?? $row['output_per_million'])
      : $row['output_per_million']);

    return (
      $uncached * $inputRate
      + $cached * $cachedRate
      + $cacheWrite5m * (float) ($row['cache_write_5m_per_million'] ?? $inputRate)
      + $cacheWrite1h * (float) ($row['cache_write_1h_per_million'] ?? $inputRate)
      + $output * $outputRate
    ) / 1_000_000;
  }

  /**
   * Estimates any supported Drupal AI operation in USD.
   */
  public function estimate(
    string $provider,
    string $operation,
    string $model,
    array $configuration = [],
    mixed $input = NULL,
    array $metadata = [],
    array $tokens = [],
  ): ?float {
    $row = $this->find($provider, $model, NULL, $operation);
    if ($row === NULL) {
      return NULL;
    }
    return match ($row['type'] ?? '') {
      'tokens' => $this->estimateTokenRow($row, $tokens),
      'image' => $this->estimateImage($row, $operation, $configuration),
      'video' => $this->estimateVideo($row, $operation, $configuration, $metadata),
      'characters' => $this->estimateCharacters($row, $input),
      'audio_hours' => $this->estimateAudioHours($row, $metadata),
      default => NULL,
    };
  }

  /**
   * Finds an active model price, including aliases.
   */
  public function find(string $provider, string $model, ?string $date = NULL, ?string $operation = NULL): ?array {
    $provider = match (strtolower(trim($provider))) {
      'x', 'xai' => 'grok',
      'claude' => 'anthropic',
      default => strtolower(trim($provider)),
    };
    $date ??= gmdate('Y-m-d');
    $exact = NULL;
    $wildcard = NULL;
    foreach ($this->getPricing() as $row) {
      $rowOperation = trim((string) ($row['operation'] ?? ''));
      if ($operation !== NULL && $rowOperation !== '' && $rowOperation !== $operation) {
        continue;
      }
      $models = array_merge(
        [(string) ($row['model'] ?? '')],
        array_map('strval', (array) ($row['aliases'] ?? [])),
      );
      if (($row['provider'] ?? '') !== $provider) {
        continue;
      }
      if (($row['effective_from'] ?? '') !== '' && $date < $row['effective_from']) {
        continue;
      }
      if (($row['effective_until'] ?? '') !== '' && $date > $row['effective_until']) {
        continue;
      }
      $isExact = in_array($model, $models, TRUE);
      $isWildcard = ($row['model'] ?? '') === '*';
      if (!$isExact && !$isWildcard) {
        continue;
      }
      $candidate = $isExact ? $exact : $wildcard;
      if ($candidate === NULL || $this->isPreferredRow($row, $candidate, $operation)) {
        if ($isExact) {
          $exact = $row;
        }
        else {
          $wildcard = $row;
        }
      }
    }
    return $exact ?? $wildcard;
  }

  /**
   * Determines whether a matching row is preferred over the current match.
   */
  private function isPreferredRow(array $row, array $current, ?string $operation): bool {
    $rowOperation = trim((string) ($row['operation'] ?? ''));
    $currentOperation = trim((string) ($current['operation'] ?? ''));
    if ($operation !== NULL && ($rowOperation !== '') !== ($currentOperation !== '')) {
      return $rowOperation !== '';
    }
    return (string) ($row['effective_from'] ?? '') > (string) ($current['effective_from'] ?? '');
  }

  /**
   * Estimates generated and edited images.
   */
  private function estimateImage(array $row, string $operation, array $configuration): float {
    $resolution = strtolower((string) ($configuration['resolution'] ?? '1k'));
    $outputRate = (float) ($row['output_per_image_' . $resolution] ?? 0);
    $count = $operation === 'text_to_image'
      ? max(1, min(4, (int) ($configuration['n'] ?? 1)))
      : 1;
    $inputCost = $operation === 'image_to_image'
      ? (float) ($row['input_per_image'] ?? 0)
      : 0;
    return $inputCost + $outputRate * $count;
  }

  /**
   * Estimates generated video.
   */
  private function estimateVideo(array $row, string $operation, array $configuration, array $metadata): float {
    $duration = (float) ($metadata['duration'] ?? $configuration['duration'] ?? 5);
    $resolution = strtolower((string) ($configuration['resolution'] ?? '480p'));
    $rate = (float) ($row['output_per_second_' . $resolution] ?? 0);
    $inputCost = $operation === 'image_to_video'
      ? (float) ($row['input_per_image'] ?? 0)
      : 0;
    return $inputCost + $duration * $rate;
  }

  /**
   * Estimates text-to-speech by source character count.
   */
  private function estimateCharacters(array $row, mixed $input): float {
    $text = is_string($input)
      ? $input
      : (is_object($input) && method_exists($input, 'getText') ? $input->getText() : '');
    return mb_strlen((string) $text) * (float) ($row['per_million_characters'] ?? 0) / 1_000_000;
  }

  /**
   * Estimates speech-to-text by source duration.
   */
  private function estimateAudioHours(array $row, array $metadata): ?float {
    if (!is_numeric($metadata['duration'] ?? NULL)) {
      return NULL;
    }
    return (float) $metadata['duration'] * (float) ($row['per_hour'] ?? 0) / 3600;
  }

  /**
   * Validates pricing rows.
   */
  private function normalize(array $rows): array {
    foreach ($rows as $index => &$row) {
      if (!is_array($row)) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d must be an object.', $index + 1));
      }
      $row['provider'] = strtolower(trim((string) ($row['provider'] ?? '')));
      $row['model'] = trim((string) ($row['model'] ?? ''));
      $row['type'] = trim((string) ($row['type'] ?? 'tokens'));
      if ($row['provider'] === '' || $row['model'] === '') {
        throw new \UnexpectedValueException(sprintf('Pricing row %d requires provider and model.', $index + 1));
      }
      if (!in_array($row['type'], ['tokens', 'image', 'video', 'characters', 'audio_hours'], TRUE)) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d has an unsupported type.', $index + 1));
      }
      if ($row['type'] === 'tokens' && !isset($row['input_per_million'], $row['output_per_million'])) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d requires input and output token rates.', $index + 1));
      }
      if (isset($row['aliases']) && (!is_array($row['aliases']) || !array_is_list($row['aliases']))) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d has invalid aliases.', $index + 1));
      }
      foreach (self::NUMERIC_FIELDS as $field) {
        if (isset($row[$field]) && (!is_numeric($row[$field]) || (float) $row[$field] < 0)) {
          throw new \UnexpectedValueException(sprintf('Pricing row %d has an invalid %s value.', $index + 1, $field));
        }
      }
      foreach (['effective_from', 'effective_until', 'checked_at'] as $field) {
        if (isset($row[$field]) && !$this->isValidDate((string) $row[$field])) {
          throw new \UnexpectedValueException(sprintf('Pricing row %d has an invalid %s date.', $index + 1, $field));
        }
      }
      if (
        isset($row['effective_from'], $row['effective_until'])
        && $row['effective_until'] < $row['effective_from']
      ) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d has an invalid effective date range.', $index + 1));
      }
    }
    unset($row);
    return array_values($rows);
  }

  /**
   * Checks that a date is a real calendar date in YYYY-MM-DD format.
   */
  private function isValidDate(string $date): bool {
    $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $value !== FALSE && $value->format('Y-m-d') === $date;
  }

}
