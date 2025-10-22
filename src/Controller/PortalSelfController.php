<?php
namespace Drupal\mh_stripe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Config\ConfigFactoryInterface;

final class PortalSelfController extends ControllerBase {
  public function __construct(private StripeHelper $helper, private AccountInterface $currentUser, private ConfigFactoryInterface $configFactory) {}
  public static function create(ContainerInterface $c): self {
    return new self($c->get('mh_stripe.helper'), $c->get('current_user'), $c->get('config.factory'));
  }

  public function portal(int $user): RedirectResponse {
    $config = $this->configFactory->get('mh_stripe.settings');
    if (!$config->get('show_member_portal_link')) {
        $this->messenger()->addError('Stripe billing portal is disabled.');
        return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
    }

    if ($this->currentUser->id() != $user && !$this->currentUser->hasPermission('open stripe portal')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }

    $account = $this->entityTypeManager()->getStorage('user')->load($user);
    if (!$account) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $field = 'field_stripe_customer_id';
    $cus = (string) ($account->get($field)->value ?? '');

    if (!$cus) {
      $this->messenger()->addError('No Stripe customer ID found for this user.');
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
    }

    try {
      $returnUrl = Url::fromRoute('entity.user.canonical', ['user' => $user], ['absolute' => TRUE])->toString();
      $portalUrl = $this->helper->createPortalUrl($cus, $returnUrl);
      return new RedirectResponse($portalUrl);
    } catch (\Exception $e) {
      $this->messenger()->addError('Could not create Stripe Billing Portal session: ' . $e->getMessage());
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
    }
  }
}
