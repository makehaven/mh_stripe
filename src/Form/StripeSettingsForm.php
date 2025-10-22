<?php

namespace Drupal\mh_stripe\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\field\FieldConfigInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StripeSettingsForm extends ConfigFormBase {

  public function __construct(private EntityFieldManagerInterface $entityFieldManager) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_field.manager'));
  }

  public function getFormId(): string {
    return 'mh_stripe_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['mh_stripe.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mh_stripe.settings');

    $fields = $this->entityFieldManager->getFieldDefinitions('user', 'user');
    $options = [];
    foreach ($fields as $field_name => $definition) {
      if ($definition instanceof FieldConfigInterface && in_array($definition->getType(), ['string', 'string_long'], TRUE)) {
        $options[$field_name] = $definition->getLabel();
      }
    }

    $current_field = (string) $config->get('customer_field');
    if ($current_field && !isset($options[$current_field])) {
      $options[$current_field] = $this->t('@field (missing)', ['@field' => $current_field]);
    }

    if (!$options) {
      $this->messenger()->addWarning($this->t('No configurable text fields were found on the User entity. Create a plain text field to store the Stripe customer id before enabling staff access.'));
    }

    $secret_set = (bool) $config->get('stripe_secret');

    $form['stripe_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Stripe secret key'),
      '#description' => $secret_set
        ? $this->t('A secret key is currently stored in Drupal configuration and is hidden here. Enter a new value to replace it, or check "Remove stored secret" to fall back to settings.php.')
        : $this->t('Optional. Enter your Stripe secret key to store it in Drupal configuration. Leave blank to rely on settings.php or the Key module.'),
      '#default_value' => '',
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['clear_stripe_secret'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove stored secret'),
      '#description' => $this->t('Check to delete the stored Stripe secret key and fall back to values provided in settings.php.'),
      '#states' => [
        'visible' => [
          ':input[name="stripe_secret"]' => ['value' => ''],
        ],
        'disabled' => [
          ':input[name="stripe_secret"]' => ['filled' => TRUE],
        ],
      ],
      '#default_value' => FALSE,
      '#access' => $secret_set,
    ];

    $form['customer_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Stripe customer ID field'),
      '#description' => $this->t('Choose the user field where the Stripe customer id should be stored.'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $current_field ?: NULL,
      '#required' => !empty($options),
    ];

    $form['portal_configuration_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Billing portal configuration ID'),
      '#description' => $this->t('Optional. Provide a Stripe Billing Portal configuration ID (pc_...) to use by default when creating portal sessions.'),
      '#default_value' => $config->get('portal_configuration_id') ?: '',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $secret = trim((string) $form_state->getValue('stripe_secret'));
    $clear = (bool) $form_state->getValue('clear_stripe_secret');
    if ($secret === '' && !$clear) {
      $stored_secret = (string) $this->config('mh_stripe.settings')->get('stripe_secret');
      $settings_secret = (string) Settings::get('stripe.secret', '');
      if ($stored_secret === '' && $settings_secret === '') {
        $form_state->setErrorByName('stripe_secret', $this->t('Provide a Stripe secret key here or in settings.php.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('mh_stripe.settings');

    $secret = trim((string) $form_state->getValue('stripe_secret'));
    $clear = (bool) $form_state->getValue('clear_stripe_secret');

    if ($secret !== '') {
      $config->set('stripe_secret', $secret);
    }
    elseif ($clear) {
      $config->set('stripe_secret', '');
    }

    $config
      ->set('customer_field', (string) $form_state->getValue('customer_field'))
      ->set('portal_configuration_id', trim((string) $form_state->getValue('portal_configuration_id')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
