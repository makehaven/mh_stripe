<?php

namespace Drupal\mh_stripe\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Stripe\StripeClient;

final class StripeHelper {
  private StripeClient $stripe;
  private string $dashboardBase;
  private string $portalInvoicesConfig;
  private string $customerField;
  private ImmutableConfig $config;

  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->config = $configFactory->get('mh_stripe.settings');

    $secret = trim((string) $this->config->get('stripe_secret'));
    if ($secret === '') {
      $secret = (string) Settings::get('stripe.secret', '');
    }
    if ($secret === '') {
      throw new \RuntimeException('Stripe secret key not configured.');
    }

    $this->stripe = new StripeClient($secret);
    $this->dashboardBase = str_contains($secret, '_test_') ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';

    $portalConfig = trim((string) $this->config->get('portal_configuration_id'));
    if ($portalConfig === '') {
      $portalConfig = (string) Settings::get('stripe.portal_configuration_invoices', '');
    }
    $this->portalInvoicesConfig = $portalConfig;

    $this->customerField = (string) $this->config->get('customer_field');
    if ($this->customerField === '') {
      $this->customerField = 'field_stripe_customer_id';
    }
  }

  public function customerFieldName(): string {
    return $this->customerField;
  }

  public function findOrCreateCustomerIdByEmail(string $email, array $createAttrs = []): string {
    $result = $this->stripe->customers->search([
      'query' => sprintf('email:"%s"', $email),
      'limit' => 5,
    ]);
    if (!empty($result->data)) {
      usort($result->data, fn($a,$b) => $b->created <=> $a->created);
      return $result->data[0]->id;
    }
    $created = $this->stripe->customers->create(array_merge(['email' => $email], $createAttrs));
    return $created->id;
  }

  public function customerDashboardUrl(string $customerId): string {
    return "{$this->dashboardBase}/customers/{$customerId}";
  }

  public function subscriptionDashboardUrl(string $subscriptionId): string {
    return "{$this->dashboardBase}/subscriptions/{$subscriptionId}";
  }

  public function createPortalUrl(string $customerId, string $returnUrl, ?string $configuration = null): string {
    $params = ['customer' => $customerId, 'return_url' => $returnUrl];
    if ($configuration) {
      $params['configuration'] = $configuration;
    } elseif ($this->portalInvoicesConfig) {
      $params['configuration'] = $this->portalInvoicesConfig;
    }
    $session = $this->stripe->billingPortal->sessions->create($params);
    return $session->url;
  }

  public function createSubscription(string $customerId, string $priceId, array $metadata = []): array {
    $sub = $this->stripe->subscriptions->create([
      'customer' => $customerId,
      'items' => [['price' => $priceId]],
      'metadata' => $metadata,
    ]);
    return ['id' => $sub->id];
  }
}
