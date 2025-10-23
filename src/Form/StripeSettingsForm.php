<?php
namespace Drupal\mh_stripe\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class StripeSettingsForm extends ConfigFormBase {
  public function getFormId() {
    return 'mh_stripe_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['mh_stripe.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('mh_stripe.settings');

    $form['setup_instructions'] = [
      '#type' => 'markup',
      '#markup' => $this->t('
        <h3>Stripe Configuration Instructions</h3>
        <p>Follow these steps to connect your Drupal site with Stripe:</p>
        <ol>
          <li>Log in to your <a href="https://dashboard.stripe.com/" target="_blank">Stripe Dashboard</a>.</li>
          <li>Navigate to the <a href="https://dashboard.stripe.com/apikeys" target="_blank">API Keys</a> section.</li>
          <li>
            <strong>To enable Test Mode</strong>, use your "Test" API keys (e.g., sk_test_...).
            <strong>To go live</strong>, use your "Live" API keys (e.g., sk_live_...).
          </li>
          <li>
            Choose your desired API key source below. For production environments, it is recommended to store keys in <code>settings.php</code> for better security.
          </li>
        </ol>
      '),
    ];

    $form['api_key_source'] = [
      '#type' => 'select',
      '#title' => $this->t('API key source'),
      '#options' => [
        'settings' => $this->t('settings.php'),
        'config' => $this->t('Module configuration'),
      ],
      '#default_value' => $config->get('api_key_source') ?: 'settings',
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key (secret)'),
      '#default_value' => $config->get('api_key'),
      '#states' => [
        'visible' => [
          ':input[name="api_key_source"]' => ['value' => 'config'],
        ],
      ],
    ];

    $form['portal_configuration_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Portal Configuration ID'),
      '#description' => $this->t('Optional, for invoices-only portal, e.g., pc_123.'),
      '#default_value' => $config->get('portal_configuration_id'),
    ];

    $form['show_member_portal_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show member portal link'),
      '#default_value' => $config->get('show_member_portal_link'),
      '#description' => $this->t('Changes in Stripe may affect other billing systems; use invoices-only Portal Configuration.'),
    ];

    $form['show_staff_customer_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show staff customer link'),
      '#default_value' => $config->get('show_staff_customer_link'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('mh_stripe.settings')
      ->set('api_key_source', $form_state->getValue('api_key_source'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('portal_configuration_id', $form_state->getValue('portal_configuration_id'))
      ->set('show_member_portal_link', $form_state->getValue('show_member_portal_link'))
      ->set('show_staff_customer_link', $form_state->getValue('show_staff_customer_link'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
