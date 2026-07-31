<?php

declare(strict_types=1);

namespace Drupal\ai_costs\Form;

use Drupal\ai_costs\Service\AiPricingCatalog;
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
    $form['model_pricing'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Model pricing JSON'),
      '#default_value' => json_encode($pricing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#rows' => 30,
      '#required' => TRUE,
      '#description' => $this->t('Saving this field creates a local override of the packaged pricing catalogue. Values are best-effort USD estimates.'),
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
    $this->configFactory->getEditable('ai_costs.settings')
      ->set('model_pricing', $pricing)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Removes the local override and restores packaged pricing.
   */
  public function restorePackagedPricing(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('ai_costs.settings')
      ->set('model_pricing', [])
      ->save();
    $this->messenger()->addStatus($this->t('Packaged AI pricing restored.'));
    $form_state->setRedirect('ai_costs.settings');
  }

}
