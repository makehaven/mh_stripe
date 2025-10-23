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
