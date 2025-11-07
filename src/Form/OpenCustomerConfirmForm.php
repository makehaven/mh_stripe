<?php

namespace Drupal\mh_stripe\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OpenCustomerConfirmForm extends ConfirmFormBase {

  private ?UserInterface $targetUser = NULL;
  private int $targetUid = 0;

  public function __construct(
    private readonly StripeHelper $helper,
    private readonly ConfigFactoryInterface $mhStripeConfigFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mh_stripe.stripe_helper'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'mh_stripe_open_customer_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $user = NULL): array {
    $this->targetUid = $this->loadTargetUser($user);
    $form = parent::buildForm($form, $form_state);
    $form['#title'] = $this->t('Open Stripe customer');
    $form['description'] = [
      '#markup' => $this->t('You will be redirected to Stripe in a new window. A customer record will be created automatically if the account does not have one.'),
    ];
    return $form;
  }

  public function getQuestion(): string {
    $account = $this->targetUser;
    $name = $account ? $account->label() : $this->targetUid;
    return $this->t('Open the Stripe customer dashboard for @name?', ['@name' => $name]);
  }

  public function getConfirmText(): string {
    return $this->t('Open in Stripe');
  }

  public function getCancelText(): string {
    return $this->t('Cancel');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.user.canonical', ['user' => $this->targetUid]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->mhStripeConfigFactory->get('mh_stripe.settings');
    if (!(bool) $config->get('show_staff_customer_link')) {
      $this->messenger()->addError($this->t('Opening Stripe customer is disabled.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $account = $this->targetUser ?? $this->entityTypeManager->getStorage('user')->load($this->targetUid);
    if (!$account) {
      throw new NotFoundHttpException();
    }

    $field = $this->helper->customerFieldName();
    if (!$account->hasField($field)) {
      $this->messenger()->addError($this->t('The configured Stripe customer field (@field) does not exist on this site. Update the MakeHaven Stripe settings.', ['@field' => $field]));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $customerId = (string) ($account->get($field)->value ?? '');

    if ($customerId === '') {
      $email = $account->getEmail();
      if (!$email) {
        $this->messenger()->addError($this->t('User has no email; cannot match Stripe customer.'));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return;
      }

      try {
        $customerId = $this->helper->findOrCreateCustomerIdByEmail($email, ['metadata' => ['drupal_uid' => (string) $this->targetUid]]);
        $account->set($field, $customerId)->save();
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Could not create Stripe customer: @message', ['@message' => $e->getMessage()]));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return;
      }
    }

    $form_state->setResponse(new TrustedRedirectResponse($this->helper->customerDashboardUrl($customerId)));
  }

  private function loadTargetUser(mixed $user): int {
    if (!is_scalar($user) && !(is_object($user) && method_exists($user, '__toString'))) {
      throw new NotFoundHttpException();
    }

    $uid = (int) $user;
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account) {
      throw new NotFoundHttpException();
    }

    $this->targetUser = $account;
    return $uid;
  }

}
