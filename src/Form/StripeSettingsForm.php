<?php

namespace Drupal\mh_stripe\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\field\FieldConfigInterface;
use Drupal\mh_stripe\Service\ExistingCustomerFetcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StripeSettingsForm extends ConfigFormBase {

  protected EntityFieldManagerInterface $entityFieldManager;
  protected ExistingCustomerFetcher $existingCustomerFetcher;

  public function __construct(
    EntityFieldManagerInterface $entityFieldManager,
    ExistingCustomerFetcher $existingCustomerFetcher,
  ) {
    $this->entityFieldManager = $entityFieldManager;
    $this->existingCustomerFetcher = $existingCustomerFetcher;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_field.manager'),
      $container->get('mh_stripe.existing_customer_fetcher'),
    );
  }

  public function getFormId(): string {
    return 'mh_stripe_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['mh_stripe.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mh_stripe.settings');

    $form['setup_instructions'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<h3>Stripe Configuration Instructions</h3><p>Follow these steps to connect your Drupal site with Stripe:</p><ol><li>Log in to your <a href="https://dashboard.stripe.com/" target="_blank">Stripe Dashboard</a>.</li><li>Navigate to the <a href="https://dashboard.stripe.com/apikeys" target="_blank">API Keys</a> section.</li><li><strong>To enable Test Mode</strong>, use your "Test" API keys (e.g., sk_test_...). <strong>To go live</strong>, use your "Live" API keys (e.g., sk_live_...).</li><li>Choose your desired API key source below. For production environments, it is recommended to store keys in <code>settings.php</code> for better security.</li></ol>'),
    ];

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

    $stored_secret = trim((string) $config->get('stripe_secret'));
    if ($stored_secret === '') {
      $stored_secret = trim((string) $config->get('api_key'));
    }
    $secret_set = $stored_secret !== '';
    $secret_preview = $secret_set ? $this->formatSecretPreview($stored_secret) : '';

    $api_source = (string) $config->get('api_key_source');
    if ($api_source === '') {
      $api_source = $secret_set ? 'config' : 'settings';
    }

    $form['api_key_source'] = [
      '#type' => 'select',
      '#title' => $this->t('API key source'),
      '#options' => [
        'settings' => $this->t('settings.php'),
        'config' => $this->t('Module configuration'),
      ],
      '#default_value' => $api_source,
      '#description' => $this->t('Choose where the Stripe secret key should be loaded from.'),
    ];

    $form['stripe_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Stripe secret key'),
      '#description' => $secret_set
        ? $this->t('Stored key: @preview. Leave the field blank to keep it, enter a new value to replace it, or check "Remove stored secret" to fall back to settings.php.', ['@preview' => $secret_preview])
        : $this->t('Optional. Enter your Stripe secret key to store it in Drupal configuration. Leave blank to rely on settings.php or the Key module.'),
      '#default_value' => '',
      '#attributes' => ['autocomplete' => 'off'],
      '#states' => [
        'visible' => [
          ':input[name="api_key_source"]' => ['value' => 'config'],
        ],
      ],
    ];

    $form['clear_stripe_secret'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove stored secret'),
      '#description' => $this->t('Check to delete the stored Stripe secret key and fall back to values provided in settings.php.'),
      '#states' => [
        'visible' => [
          ':input[name="api_key_source"]' => ['value' => 'config'],
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
      '#description' => $this->t('Optional but recommended. Create a Billing Portal configuration in Stripe (Settings → Billing → Customer portal → + Add configuration), enable only the features you want members to access, then copy the generated ID (pc_...) into this field. Use the Test configuration ID on dev/staging and the Live ID on production so each environment opens the matching portal experience.'),
      '#default_value' => $config->get('portal_configuration_id') ?: '',
    ];

    $form['show_member_portal_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show member portal link'),
      '#default_value' => (bool) $config->get('show_member_portal_link'),
      '#description' => $this->t('Changes in Stripe may affect other billing systems; use invoices-only Portal Configuration.'),
    ];

    $form['show_staff_customer_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show staff customer link'),
      '#default_value' => (bool) $config->get('show_staff_customer_link'),
    ];

    $form = parent::buildForm($form, $form_state);

    $form['actions']['fetch_existing_customers'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch existing Stripe customers'),
      '#button_type' => 'secondary',
      '#submit' => ['::submitFetchExistingCustomers'],
      '#limit_validation_errors' => [],
      '#description' => $this->t('Batch fetch existing customer IDs for users whose emails already match a Stripe customer.'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $source = (string) $form_state->getValue('api_key_source');
    if ($source !== 'config') {
      return;
    }

    $secret = trim((string) $form_state->getValue('stripe_secret'));
    $clear = (bool) $form_state->getValue('clear_stripe_secret');
    if ($secret === '' && !$clear) {
      $stored_secret = trim((string) $this->config('mh_stripe.settings')->get('stripe_secret'));
      if ($stored_secret === '') {
        $stored_secret = trim((string) $this->config('mh_stripe.settings')->get('api_key'));
      }
      $settings_secret = (string) Settings::get('stripe.secret', '');
      if ($stored_secret === '' && $settings_secret === '') {
        $form_state->setErrorByName('stripe_secret', $this->t('Provide a Stripe secret key here or in settings.php.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('mh_stripe.settings');

    $source = (string) $form_state->getValue('api_key_source');
    $secret = trim((string) $form_state->getValue('stripe_secret'));
    $clear = (bool) $form_state->getValue('clear_stripe_secret');

    $config->set('api_key_source', $source);

    if ($source === 'config') {
      if ($secret !== '') {
        $config->set('stripe_secret', $secret);
        $config->set('api_key', $secret);
      }
      elseif ($clear) {
        $config->set('stripe_secret', '');
        $config->set('api_key', '');
      }
    }
    else {
      $config->set('stripe_secret', '');
      $config->set('api_key', '');
    }

    $config
      ->set('customer_field', (string) $form_state->getValue('customer_field'))
      ->set('portal_configuration_id', trim((string) $form_state->getValue('portal_configuration_id')))
      ->set('show_member_portal_link', (bool) $form_state->getValue('show_member_portal_link'))
      ->set('show_staff_customer_link', (bool) $form_state->getValue('show_staff_customer_link'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  public function submitFetchExistingCustomers(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('mh_stripe.settings');
    $field = (string) $config->get('customer_field');
    if ($field === '') {
      $this->messenger()->addError($this->t('Select and save a Stripe customer ID field before running the fetch.'));
      return;
    }

    $uids = $this->existingCustomerFetcher->candidateUserIds();
    if (empty($uids)) {
      $this->messenger()->addStatus($this->t('All users already have a stored Stripe customer ID or lack an email address.'));
      return;
    }

    $batch = (new BatchBuilder())
      ->setTitle($this->t('Fetching existing Stripe customer IDs'))
      ->setInitMessage($this->t('Preparing batch fetch...'))
      ->setProgressMessage($this->t('Processed @current of @total users.'))
      ->setErrorMessage($this->t('The fetch process encountered an error.'))
      ->setFinishCallback([self::class, 'batchFetchExistingFinished']);

    foreach (array_chunk($uids, 25) as $chunk) {
      $batch->addOperation([self::class, 'batchFetchExistingProcess'], [$chunk]);
    }

    batch_set($batch->toArray());
    $form_state->setRebuild();
  }

  public static function batchFetchExistingProcess(array $uids, array &$context): void {
    /** @var \Drupal\mh_stripe\Service\ExistingCustomerFetcher $fetcher */
    $fetcher = \Drupal::service('mh_stripe.existing_customer_fetcher');
    $results = $context['results'] ?? [
      'updated' => 0,
      'skipped' => 0,
      'missing_field' => 0,
      'no_email' => 0,
      'errors' => 0,
    ];

    foreach ($uids as $uid) {
      $result = $fetcher->processUser((int) $uid);
      switch ($result['status']) {
        case ExistingCustomerFetcher::STATUS_UPDATED:
          $results['updated']++;
          break;

        case ExistingCustomerFetcher::STATUS_SKIPPED:
          $results['skipped']++;
          break;

        case ExistingCustomerFetcher::STATUS_MISSING_FIELD:
          $results['missing_field']++;
          break;

        case ExistingCustomerFetcher::STATUS_NO_EMAIL:
          $results['no_email']++;
          break;

        case ExistingCustomerFetcher::STATUS_ERROR:
        default:
          $results['errors']++;
          break;
      }

      $context['message'] = \Drupal::translation()->translate('Processing user @uid', ['@uid' => $uid]);
    }

    $context['results'] = $results;
  }

  public static function batchFetchExistingFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $translator = \Drupal::translation();

    if (!$success) {
      $messenger->addError($translator->translate('Fetching existing Stripe customers did not complete.'));
      return;
    }

    $updated = (int) ($results['updated'] ?? 0);
    $skipped = (int) ($results['skipped'] ?? 0);
    $missingField = (int) ($results['missing_field'] ?? 0);
    $noEmail = (int) ($results['no_email'] ?? 0);
    $errors = (int) ($results['errors'] ?? 0);

    $messenger->addStatus($translator->translate('Fetched existing Stripe customers. Updated @updated, skipped @skipped, missing field @missing, no email @no_email, errors @errors.', [
      '@updated' => $updated,
      '@skipped' => $skipped,
      '@missing' => $missingField,
      '@no_email' => $noEmail,
      '@errors' => $errors,
    ]));
  }

  private function formatSecretPreview(string $secret): string {
    $secret = trim($secret);
    if ($secret === '') {
      return '';
    }

    if (strlen($secret) <= 8) {
      return $secret;
    }

    $start = substr($secret, 0, 8);
    $end = substr($secret, -4);
    return $start . '...' . $end;
  }

}
