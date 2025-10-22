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
    if (!empty($result->data)) {
      usort($result->data, fn($a,$b) => $b->created <=> $a->created);
      return $result->data[0]->id;
    }
    $created = $this->getStripe()->customers->create(array_merge(['email' => $email], $createAttrs));
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
    $session = $this->getStripe()->billingPortal->sessions->create($params);
    return $session->url;
  }

  public function createSubscription(string $customerId, string $priceId, array $metadata = []): array {
    $sub = $this->getStripe()->subscriptions->create([
      'customer' => $customerId,
      'items' => [['price' => $priceId]],
      'metadata' => $metadata,
    ]);
    return ['id' => $sub->id];
  }
}
