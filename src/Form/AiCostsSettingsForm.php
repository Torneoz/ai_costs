<?php

declare(strict_types=1);

namespace Drupal\ai_costs\Form;

use Drupal\ai_costs\Service\AiPricingCatalog;
use Drupal\ai_costs\Service\PricingScheduleFetcher;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures shared AI pricing data.
 */
final class AiCostsSettingsForm extends ConfigFormBase {

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly AiPricingCatalog $pricingCatalog,
    private readonly PricingScheduleFetcher $pricingScheduleFetcher,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai_costs.pricing_catalog'),
      $container->get('ai_costs.pricing_schedule_fetcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_costs_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_costs.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_costs.settings');
    $pricing = $this->pricingCatalog->getPricing();
    $counts = array_count_values(array_map(
      static fn(array $row): string => (string) ($row['provider'] ?? 'unknown'),
      $pricing,
    ));
    $form['introduction'] = [
      '#markup' => '<p>' . $this->t('Maintain a shared provider-neutral pricing catalogue for AI cost estimates. Provider-reported request costs should take precedence whenever available.') . '</p>',
    ];
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Packaged providers'),
      '#markup' => $this->t('@openai OpenAI rows, @anthropic Anthropic rows, and @grok Grok/xAI rows are currently active.', [
        '@openai' => $counts['openai'] ?? 0,
        '@anthropic' => $counts['anthropic'] ?? 0,
        '@grok' => $counts['grok'] ?? 0,
      ]),
    ];
    $form['provenance'] = [
      '#type' => 'item',
      '#title' => $this->t('Active pricing provenance'),
      '#markup' => $this->t('Source: @source. Last retrieved or reviewed: @checked.', [
        '@source' => (string) ($config->get('pricing_source') ?: 'packaged'),
        '@checked' => (string) ($config->get('pricing_checked_at') ?: 'not recorded'),
      ]),
    ];
    $form['model_pricing'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Model pricing JSON'),
      '#default_value' => json_encode($pricing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#rows' => 30,
      '#required' => TRUE,
      '#description' => $this->t('Saving this field creates a local override of the packaged pricing catalogue. Values are best-effort USD estimates.'),
    ];
    $form['load_pricing'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load latest maintained pricing'),
      '#submit' => ['::loadPricingSchedule'],
      '#limit_validation_errors' => [],
    ];
    $form['restore_pricing'] = [
      '#type' => 'submit',
      '#value' => $this->t('Restore packaged pricing'),
      '#submit' => ['::restorePackagedPricing'],
      '#limit_validation_errors' => [],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * Loads the latest maintained schedule into the unsaved form.
   */
  public function loadPricingSchedule(array &$form, FormStateInterface $form_state): void {
    try {
      $schedule = $this->pricingScheduleFetcher->fetch();
      $form_state->set('ai_costs_pricing_schedule', $schedule);
      $input = $form_state->getUserInput();
      $input['model_pricing'] = $schedule['json'];
      $form_state->setValue('model_pricing', $schedule['json']);
      $form_state->setUserInput($input);
      $this->messenger()->addStatus($this->formatPlural(
        $schedule['rows'],
        'Loaded one maintained pricing row. Review it and save the form to activate it.',
        'Loaded @count maintained pricing rows. Review them and save the form to activate them.',
      ));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('The maintained pricing schedule could not be loaded: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $normalized = $this->pricingCatalog->normalizeJson(
        (string) $form_state->getValue('model_pricing'),
      );
      $form_state->setValue('model_pricing', $normalized);
    }
    catch (\Throwable $exception) {
      $form_state->setErrorByName('model_pricing', $this->t('Enter valid model pricing JSON: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $pricing = json_decode(
      (string) $form_state->getValue('model_pricing'),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $normalized = (string) json_encode(
      $pricing,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $hash = hash('sha256', $normalized);
    $schedule = (array) $form_state->get('ai_costs_pricing_schedule');
    $source = $hash === ($schedule['hash'] ?? NULL)
      ? (string) ($schedule['source'] ?? '')
      : 'manual';
    $checkedAt = $hash === ($schedule['hash'] ?? NULL)
      ? (string) ($schedule['checked_at'] ?? '')
      : gmdate(DATE_ATOM);
    $this->configFactory->getEditable('ai_costs.settings')
      ->set('model_pricing', $pricing)
      ->set('pricing_source', $source ?: 'manual')
      ->set('pricing_checked_at', $checkedAt)
      ->set('pricing_hash', $hash)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Removes the local override and restores packaged pricing.
   */
  public function restorePackagedPricing(array &$form, FormStateInterface $form_state): void {
    $json = $this->pricingCatalog->normalizeJson(
      (string) json_encode($this->pricingCatalog->getPackagedPricing(), JSON_THROW_ON_ERROR),
    );
    $this->configFactory->getEditable('ai_costs.settings')
      ->set('model_pricing', [])
      ->set('pricing_source', 'packaged')
      ->set('pricing_checked_at', gmdate(DATE_ATOM))
      ->set('pricing_hash', hash('sha256', $json))
      ->save();
    $this->messenger()->addStatus($this->t('Packaged AI pricing restored.'));
    $form_state->setRedirect('ai_costs.settings');
  }

}
