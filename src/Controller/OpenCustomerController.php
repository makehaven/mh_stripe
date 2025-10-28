<?php

namespace Drupal\mh_stripe\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OpenCustomerController extends ControllerBase {

  public function __construct(private StripeHelper $helper, private ConfigFactoryInterface $mhStripeConfigFactory) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('mh_stripe.helper'), $container->get('config.factory'));
  }

  public function open(int $user): RedirectResponse {
    $config = $this->mhStripeConfigFactory->get('mh_stripe.settings');
    $userUrl = Url::fromRoute('entity.user.canonical', ['user' => $user]);
    $redirect = new RedirectResponse($userUrl->toString());

    if (!(bool) $config->get('show_staff_customer_link')) {
      $this->messenger()->addError($this->t('Opening Stripe customer is disabled.'));
      return $redirect;
    }

    $account = $this->entityTypeManager()->getStorage('user')->load($user);
    if (!$account) {
      throw new NotFoundHttpException();
    }

    $field = $this->helper->customerFieldName();
    if (!$account->hasField($field)) {
      $this->messenger()->addError($this->t('The configured Stripe customer field (@field) does not exist on this site. Update the MakeHaven Stripe settings.', ['@field' => $field]));
      return $redirect;
    }

    $customerId = (string) ($account->get($field)->value ?? '');

    if ($customerId === '') {
      $email = $account->getEmail();
      if (!$email) {
        $this->messenger()->addError($this->t('User has no email; cannot match Stripe customer.'));
        return $redirect;
      }

      try {
        $customerId = $this->helper->findOrCreateCustomerIdByEmail($email, ['metadata' => ['drupal_uid' => (string) $user]]);
        $account->set($field, $customerId)->save();
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Could not create Stripe customer: @message', ['@message' => $e->getMessage()]));
        return $redirect;
      }
    }

    return new TrustedRedirectResponse($this->helper->customerDashboardUrl($customerId));
  }

}
