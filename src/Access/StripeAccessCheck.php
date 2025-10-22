<?php
namespace Drupal\mh_stripe\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

class StripeAccessCheck implements AccessInterface {
  public function __construct(private ConfigFactoryInterface $configFactory) {}

  public function access(Route $route, AccountInterface $account, $user = NULL) {
    $config = $this->configFactory->get('mh_stripe.settings');
    $routeName = $route->getPath();

    if ($routeName === '/admin/stripe/open-customer/{user}') {
      return AccessResult::allowedIf($config->get('show_staff_customer_link') && $account->hasPermission('open stripe customer'));
    }

    if ($routeName === '/user/{user}/billing/stripe') {
      return AccessResult::allowedIf($config->get('show_member_portal_link') && ($account->id() == $user || $account->hasPermission('open stripe portal')));
    }

    return AccessResult::neutral();
  }
}
