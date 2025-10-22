<?php
namespace Drupal\mh_stripe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class OpenCustomerController extends ControllerBase {
  public function __construct(private StripeHelper $helper) {}
  public static function create(ContainerInterface $c): self { return new self($c->get('mh_stripe.helper')); }

  public function open(int $user): RedirectResponse {
    $account = $this->entityTypeManager()->getStorage('user')->load($user);
    if (!$account) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(); }

    $field = $this->helper->customerFieldName();
    if (!$account->hasField($field)) {
      $this->messenger()->addError($this->t('The configured Stripe customer field (@field) does not exist on this site. Update the MakeHaven Stripe settings.', ['@field' => $field]));
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
    }
    $cus = (string) ($account->get($field)->value ?? '');

    if (!$cus) {
      $email = $account->getEmail();
      if (!$email) {
        $this->messenger()->addError('User has no email; cannot match Stripe customer.');
        return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => $user])->toString());
      }
      $cus = $this->helper->findOrCreateCustomerIdByEmail($email, ['metadata' => ['drupal_uid' => (string) $user]]);
      $account->set($field, $cus)->save();
    }

    return new RedirectResponse($this->helper->customerDashboardUrl($cus));
  }
}
