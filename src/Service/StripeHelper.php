<?php

namespace Drupal\mh_stripe\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Stripe\StripeClient;

final class StripeHelper {

  private ?StripeClient $stripe = NULL;
  private string $dashboardBase = 'https://dashboard.stripe.com';
  private string $portalInvoicesConfig = '';
  private string $customerField;
  private ImmutableConfig $config;

  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->config = $configFactory->get('mh_stripe.settings');

    $secret = $this->resolveSecret();
    if ($secret !== '') {
      $this->stripe = new StripeClient($secret);
      $this->dashboardBase = str_contains($secret, '_test_') ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';
    }

    $portalConfig = trim((string) $this->config->get('portal_configuration_id'));
    if ($portalConfig === '') {
      $portalConfig = (string) Settings::get('stripe.portal_configuration_invoices', '');
    }
    $this->portalInvoicesConfig = $portalConfig;

    $field = (string) $this->config->get('customer_field');
    $this->customerField = $field !== '' ? $field : 'field_stripe_customer_id';
  }

  public function customerFieldName(): string {
    return $this->customerField;
  }

  public function findOrCreateCustomerIdByEmail(string $email, array $createAttrs = []): string {
    $result = $this->getStripe()->customers->search([
      'query' => sprintf('email:"%s"', $email),
      'limit' => 5,
    ]);
    if (!empty($result->data)) {
      usort($result->data, static fn($a, $b) => $b->created <=> $a->created);
      return $result->data[0]->id;
    }

    $created = $this->getStripe()->customers->create(array_merge(['email' => $email], $createAttrs));
    return $created->id;
  }

  public function customerDashboardUrl(string $customerId): string {
    return sprintf('%s/customers/%s', $this->dashboardBase, $customerId);
  }

  public function subscriptionDashboardUrl(string $subscriptionId): string {
    return sprintf('%s/subscriptions/%s', $this->dashboardBase, $subscriptionId);
  }

  public function createPortalUrl(string $customerId, string $returnUrl, ?string $configuration = NULL): string {
    $params = [
      'customer' => $customerId,
      'return_url' => $returnUrl,
    ];

    if ($configuration) {
      $params['configuration'] = $configuration;
    }
    elseif ($this->portalInvoicesConfig !== '') {
      $params['configuration'] = $this->portalInvoicesConfig;
    }

    $session = $this->getStripe()->billingPortal->sessions->create($params);
    return $session->url;
  }

  public function createSubscription(string $customerId, string $priceId, array $metadata = []): array {
    $sub = $this->getStripe()->subscriptions->create([
      'customer' => $customerId,
      'items' => [['price' => $priceId]],
      'metadata' => $metadata,
    ]);

    return [
      'id' => $sub->id,
      'status' => $sub->status,
      'client_secret' => $sub->latest_invoice?->payment_intent?->client_secret,
    ];
  }

  private function getStripe(): StripeClient {
    if (!$this->stripe) {
      throw new \RuntimeException('Stripe secret key not configured.');
    }

    return $this->stripe;
  }

  private function resolveSecret(): string {
    $source = (string) $this->config->get('api_key_source');

    if ($source === 'config') {
      $secret = trim((string) $this->config->get('stripe_secret'));
      if ($secret === '') {
        $secret = trim((string) $this->config->get('api_key'));
      }
      return $secret;
    }

    $secret = (string) Settings::get('stripe.secret', '');
    if ($secret !== '') {
      return $secret;
    }

    $fallback = trim((string) $this->config->get('stripe_secret'));
    if ($fallback === '') {
      $fallback = trim((string) $this->config->get('api_key'));
    }
    return $fallback;
  }

}
