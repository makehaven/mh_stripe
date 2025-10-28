<?php
namespace Drupal\mh_stripe\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\Routing\Route;

class StripeAccessCheck implements AccessInterface {

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function access(Route $route, AccountInterface $account, $user = NULL) {
    $config = $this->configFactory->get('mh_stripe.settings');
    $routeName = $route->getPath();

    if ($routeName === '/admin/stripe/open-customer/{user}') {
      return AccessResult::allowedIf($config->get('show_staff_customer_link') && $account->hasPermission('open stripe customer'))
        ->cachePerPermissions();
    }

    if ($routeName === '/user/{user}/billing/stripe') {
      $target = $this->resolveUser($user);
      if (!$target) {
        return AccessResult::forbidden()->cachePerPermissions();
      }

      $field = (string) $config->get('customer_field');
      if ($field === '') {
        $field = 'field_stripe_customer_id';
      }

      $hasCustomer = $target->hasField($field) && !$target->get($field)->isEmpty();
      $allowedAccount = ((int) $account->id() === (int) $target->id()) || $account->hasPermission('open stripe portal');

      return AccessResult::allowedIf($config->get('show_member_portal_link') && $hasCustomer && $allowedAccount)
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($target);
    }

    return AccessResult::neutral();
  }

  private function resolveUser(mixed $user): ?UserInterface {
    if ($user instanceof UserInterface) {
      return $user;
    }

    if (!is_scalar($user) && !(is_object($user) && method_exists($user, '__toString'))) {
      return NULL;
    }

    $uid = (string) $user;
    if ($uid === '' || !ctype_digit($uid)) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('user')->load((int) $uid);
  }
}
