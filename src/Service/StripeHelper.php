<?php

namespace Drupal\mh_stripe\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Stripe\StripeClient;

final class StripeHelper {
  private ?StripeClient $stripe = NULL;
  private string $dashboardBase;
  private string $portalInvoicesConfig;

  public function __construct(ConfigFactoryInterface $configFactory) {
    $config = $configFactory->get('mh_stripe.settings');
    $secret = '';
    if ($config->get('api_key_source') === 'settings') {
      $secret = (string) Settings::get('stripe.secret', '');
    } else {
      $secret = (string) $config->get('api_key');
    }

    if (!$secret) {
      return; // Defer exception until a method is actually called.
    }

    $this->stripe = new StripeClient($secret);
    $this->dashboardBase = str_contains($secret, '_test_') ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';
    $this->portalInvoicesConfig = (string) $config->get('portal_configuration_id');
  }

  private function getStripe(): StripeClient {
    if (!$this->stripe) {
      throw new \RuntimeException('Stripe secret key not configured.');
    }
    return $this->stripe;
  }

  public function findOrCreateCustomerIdByEmail(string $email, array $createAttrs = []): string {
    $result = $this->getStripe()->customers->search([
      'query' => sprintf('email:"%s"', $email),
      'limit' => 5,
    ]);
    if ($result->data) {
      usort($result->data, fn($a,$b) => $b->created <=> $a->created);
      return $result->data[0]->id;
    }
    $created = $this->getStripe()->customers->create(array_merge(['email' => $email], $createAttrs));
    return $created->id;
  }

  public function customerDashboardUrl(string $customerId): string {
    return sprintf('%s/customers/%s', $this->dashboardBase, $customerId);
  }

  public function createPortalUrl(string $customerId, string $returnUrl): string {
    $params = [
      'customer' => $customerId,
      'return_url' => $returnUrl,
    ];
    if ($this->portalInvoicesConfig && str_starts_with($this->portalInvoicesConfig, 'pc_')) {
      $params['configuration'] = $this->portalInvoicesConfig;
    } elseif ($this->portalInvoicesConfig) {
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
}
