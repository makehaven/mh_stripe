<?php

namespace Drupal\mh_stripe\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PortalSelfController extends ControllerBase {

  public function __construct(private StripeHelper $helper, private AccountInterface $mhStripeCurrentUser, private ConfigFactoryInterface $mhStripeConfigFactory) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('mh_stripe.helper'), $container->get('current_user'), $container->get('config.factory'));
  }

  public function portal(int $user): RedirectResponse {
    $config = $this->mhStripeConfigFactory->get('mh_stripe.settings');
    $userUrl = Url::fromRoute('entity.user.canonical', ['user' => $user]);
    $redirect = new RedirectResponse($userUrl->toString());

    if (!(bool) $config->get('show_member_portal_link')) {
      $this->messenger()->addError($this->t('Stripe billing portal is disabled.'));
      return $redirect;
    }

    if ((int) $this->mhStripeCurrentUser->id() !== $user && !$this->mhStripeCurrentUser->hasPermission('open stripe portal')) {
      throw new AccessDeniedHttpException();
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
      $this->messenger()->addError($this->t('No Stripe customer ID found for this user.'));
      return $redirect;
    }

    try {
      $returnUrl = Url::fromRoute('entity.user.canonical', ['user' => $user], ['absolute' => TRUE])->toString();
      $portalUrl = $this->helper->createPortalUrl($customerId, $returnUrl);
      return new TrustedRedirectResponse($portalUrl);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Could not create Stripe Billing Portal session: @message', ['@message' => $e->getMessage()]));
      return $redirect;
    }
  }

}
