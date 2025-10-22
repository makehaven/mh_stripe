<?php
namespace Drupal\mh_stripe\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class OpenCustomerController extends ControllerBase {
  public function __construct(private StripeHelper $helper, private ConfigFactoryInterface $configFactory) {}
  public static function create(ContainerInterface $c): self {
    return new self($c->get('mh_stripe.helper'), $c->get('config.factory'));
  }

  public function open(int $user): RedirectResponse {
    $config = $this->configFactory->get('mh_stripe.settings');
    if (!$config->get('show_staff_customer_link')) {
      $this->messenger()->addError('Opening Stripe customer is disabled.');
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
    }

    $account = $this->entityTypeManager()->getStorage('user')->load($user);
    if (!$account) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $field = 'field_stripe_customer_id';
    $cus = (string) ($account->get($field)->value ?? '');

    if (!$cus) {
      $email = $account->getEmail();
      if (!$email) {
        $this->messenger()->addError('User has no email; cannot match Stripe customer.');
        return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
      }
      try {
        $cus = $this->helper->findOrCreateCustomerIdByEmail($email, ['metadata' => ['drupal_uid' => (string) $user]]);
        $account->set($field, $cus)->save();
      } catch (\Exception $e) {
        $this->messenger()->addError('Could not create Stripe customer: ' . $e->getMessage());
        return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
      }
    }

    return new RedirectResponse($this->helper->customerDashboardUrl($cus));
  }
}
