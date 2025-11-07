<?php

namespace Drupal\mh_stripe\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

final class ExistingCustomerFetcher {

  public const STATUS_UPDATED = 'updated';
  public const STATUS_SKIPPED = 'skipped';
  public const STATUS_MISSING_FIELD = 'missing_field';
  public const STATUS_NO_EMAIL = 'no_email';
  public const STATUS_ERROR = 'error';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StripeHelper $stripeHelper,
  ) {}

  public function candidateUserIds(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $field = $this->stripeHelper->customerFieldName();

    return $storage->getQuery()
      ->condition($field, NULL, 'IS NULL')
      ->condition('mail', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();
  }

  public function processUser(int $uid, bool $dryRun = FALSE): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $user = $storage->load($uid);

    if (!$user) {
      return [
        'status' => self::STATUS_ERROR,
        'uid' => $uid,
        'email' => '',
        'message' => 'User not found.',
        'dry_run' => $dryRun,
      ];
    }

    $field = $this->stripeHelper->customerFieldName();
    if (!$user->hasField($field)) {
      return [
        'status' => self::STATUS_MISSING_FIELD,
        'uid' => $uid,
        'email' => (string) $user->getEmail(),
        'message' => 'Configured customer field is missing from this site.',
        'dry_run' => $dryRun,
      ];
    }

    $email = (string) $user->getEmail();
    if ($email === '') {
      return [
        'status' => self::STATUS_NO_EMAIL,
        'uid' => $uid,
        'email' => $email,
        'message' => 'User has no email address.',
        'dry_run' => $dryRun,
      ];
    }

    try {
      $customerId = $this->stripeHelper->findExistingCustomerIdByEmail($email);
    }
    catch (\Throwable $e) {
      return [
        'status' => self::STATUS_ERROR,
        'uid' => $uid,
        'email' => $email,
        'message' => $e->getMessage(),
        'dry_run' => $dryRun,
      ];
    }

    if (!$customerId) {
      return [
        'status' => self::STATUS_SKIPPED,
        'uid' => $uid,
        'email' => $email,
        'message' => 'No existing Stripe customer found for this email.',
        'dry_run' => $dryRun,
      ];
    }

    if (!$dryRun) {
      $user->set($field, $customerId)->save();
    }

    return [
      'status' => self::STATUS_UPDATED,
      'uid' => $uid,
      'email' => $email,
      'customer_id' => $customerId,
      'dry_run' => $dryRun,
    ];
  }

}
