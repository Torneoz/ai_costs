# Provider pricing retrieval plan

The administrator-facing updater downloads the module-maintained
`data/pricing.json` from a fixed HTTPS URL. It does not claim that this file was
generated directly by a provider. The official-provider ingestion pipeline
should remain a maintainer tool until its output has been reviewed.

## Proposed entry point

```php
retrieveProviderPricing(
  ProviderPricingAdapterInterface $adapter,
  PricingSnapshot $previous,
): PricingRetrievalResult
```

The result should contain normalized candidate rows, source URLs, retrieval
time, response hashes, parser version, warnings, and a semantic diff from the
previous accepted snapshot. Retrieval must never update active Drupal
configuration directly.

## Adapter workflow

1. Fetch one allowlisted official HTTPS URL with redirects disabled, strict
   time and byte limits, conditional requests, and an explicit user agent.
2. Prefer provider-owned machine-readable data, JSON-LD, or stable embedded
   application data. Use an HTML table parser only for a specifically versioned
   provider adapter.
3. Extract displayed model names, billing units, currencies, context tiers,
   cache rates, media dimensions, and effective dates without guessing missing
   values.
4. Map provider labels to canonical model IDs using a reviewed alias table.
5. Validate units and invariants: non-negative rates, known currencies,
   complete required fields, unique row identities, valid date ranges, and
   plausible change thresholds.
6. Compare candidates with the previous snapshot. Quarantine removals, model
   renames, unit changes, and large price changes for human review.
7. Store immutable evidence: final URL, retrieval timestamp, response hash,
   relevant source excerpt or structured payload hash, and parser version.
8. Publish a signed, reviewed `pricing.json` commit. Drupal sites may then use
   the safe maintained-schedule updater to preview and save it.

## Reliability rules

- Provider-reported request cost always outranks catalogue estimates.
- A parser failure leaves the last accepted schedule active.
- No generic CSS selectors or LLM-only extraction may publish prices.
- Every adapter uses fixture-based tests captured from the official source.
- Scheduled retrieval opens a proposed change; it never auto-merges it.
- At least one reviewer verifies source evidence when rates or units change.

OpenAI, Anthropic, and xAI need separate adapters because their official pages
use different structures and billing concepts. Shared HTTP, evidence, diff,
validation, and publication components should be provider-neutral.

## Initial provider registry

| Provider | Allowlisted official source | Adapter focus |
| --- | --- | --- |
| OpenAI | `https://developers.openai.com/api/docs/models/compare` | Per-model token cards and cached-input rates |
| Anthropic | `https://platform.claude.com/docs/en/about-claude/pricing` | Token table, cache-write/read rates, date tiers, and modifiers |
| xAI | `https://docs.x.ai/developers/pricing` | Short/long-context token table, tools, images, video, and audio |

Each adapter should declare the source-layout fingerprint it understands.
Retrieval may continue when unrelated page content changes, but publishing must
stop when the pricing container, headings, billing units, or column layout no
longer match that fingerprint.
