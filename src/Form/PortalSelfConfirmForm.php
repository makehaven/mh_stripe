<?php

namespace Drupal\mh_stripe\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\mh_stripe\Service\StripeHelper;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PortalSelfConfirmForm extends ConfirmFormBase {

  private ?UserInterface $targetUser = NULL;
  private int $targetUid = 0;

  public function __construct(
    private readonly StripeHelper $helper,
    private readonly AccountInterface $currentUser,
    private readonly ConfigFactoryInterface $mhStripeConfigFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mh_stripe.stripe_helper'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'mh_stripe_portal_self_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $user = NULL): array {
    $this->targetUid = $this->loadTargetUser($user);

    if ((int) $this->currentUser->id() !== $this->targetUid && !$this->currentUser->hasPermission('open stripe portal')) {
      throw new AccessDeniedHttpException();
    }

    $form = parent::buildForm($form, $form_state);
    $form['#title'] = $this->t('Open Stripe invoices');
    $form['description'] = [
      '#markup' => $this->t('You will be redirected to Stripe in a new window to review invoices.'),
    ];

    return $form;
  }

  public function getQuestion(): string {
    $account = $this->targetUser;
    $name = $account ? $account->label() : $this->targetUid;
    return $this->t('Continue to the Stripe billing portal for @name?', ['@name' => $name]);
  }

  public function getConfirmText(): string {
    return $this->t('Continue to Stripe');
  }

  public function getCancelText(): string {
    return $this->t('Cancel');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.user.canonical', ['user' => $this->targetUid]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->mhStripeConfigFactory->get('mh_stripe.settings');
    if (!(bool) $config->get('show_member_portal_link')) {
      $this->messenger()->addError($this->t('Stripe billing portal is disabled.'));
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
      $this->messenger()->addError($this->t('No Stripe customer ID found for this user.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    try {
      $returnUrl = Url::fromRoute('entity.user.canonical', ['user' => $this->targetUid], ['absolute' => TRUE])->toString();
      $portalUrl = $this->helper->createPortalUrl($customerId, $returnUrl);
      $form_state->setResponse(new TrustedRedirectResponse($portalUrl));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Could not create Stripe Billing Portal session: @message', ['@message' => $e->getMessage()]));
      $form_state->setRedirectUrl($this->getCancelUrl());
    }
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
