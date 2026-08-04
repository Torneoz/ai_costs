# AI Costs

AI Costs provides a shared, provider-neutral pricing catalogue and best-effort
cost estimator for Drupal AI integrations.

## Features

- Packaged OpenAI, Anthropic, and Grok/xAI model pricing.
- Administrator-editable JSON overrides.
- Model aliases, provider aliases, effective dates, and long-context pricing.
- Estimates for tokens, images, video, text-to-speech characters, and
  speech-to-text duration.
- A reusable `ai_costs.pricing_catalog` service.

## Installation

Install and enable the module, then visit:

`/admin/config/ai/costs`

The packaged schedule is used until an administrator saves an override.
**Restore packaged pricing** removes the override.
**Load latest maintained pricing** downloads and validates the current
module-maintained schedule for review; it does not scrape provider websites or
activate changes until the form is saved.

The design for a separate, auditable official-provider ingestion pipeline is
documented in `docs/provider-pricing-retrieval.md`.

## Service usage

```php
$catalog = \Drupal::service('ai_costs.pricing_catalog');
$cost = $catalog->estimateTokens('openai', 'gpt-5.6-luna', [
  'input_tokens' => 1000,
  'cached_input_tokens' => 200,
  'output_tokens' => 300,
]);
```

Estimates are informational. Provider-reported request costs should take
precedence whenever available.

## Status

This is an alpha release. Pricing changes frequently and must be verified
against current provider documentation before billing or financial decisions.
