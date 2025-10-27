<?php
namespace Drupal\mh_stripe\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mh_stripe\Service\StripeHelper;
use Drush\Commands\DrushCommands;

class MhStripeCommands extends DrushCommands {
  public function __construct(
    private StripeHelper $stripeHelper,
    private EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  /**
   * Backfill Stripe customer IDs for users.
   *
   * @command mh-stripe:backfill-customers
   * @option dry-run Don't save the customer ID to the user.
   * @aliases mh-stripe-backfill
   */
  public function backfillCustomers($options = ['dry-run' => FALSE]) {
    $userStorage = $this->entityTypeManager->getStorage('user');
    $field = $this->stripeHelper->customerFieldName();

    $query = $userStorage->getQuery()
      ->condition($field, NULL, 'IS NULL')
      ->condition('mail', NULL, 'IS NOT NULL');
    $uids = $query->accessCheck(FALSE)->execute();

    if (empty($uids)) {
      $this->io()->success('No users to backfill.');
      return;
    }

    $this->io()->progressStart(count($uids));

    foreach ($uids as $uid) {
      $user = $userStorage->load($uid);
      $email = $user->getEmail();

      if (!$user->hasField($field)) {
        $this->logger()->error(dt('User @uid (@email) is missing the configured Stripe customer field (@field).', ['@uid' => $uid, '@email' => $email, '@field' => $field]));
        $this->io()->progressAdvance();
        continue;
      }

      try {
        $customerId = $this->stripeHelper->findOrCreateCustomerIdByEmail($email, ['metadata' => ['drupal_uid' => (string) $uid]]);
        if (!$options['dry-run']) {
          $user->set($field, $customerId)->save();
          $this->logger()->info(dt('User @uid (@email) backfilled with customer ID @customer_id.', ['@uid' => $uid, '@email' => $email, '@customer_id' => $customerId]));
        } else {
          $this->logger()->info(dt('User @uid (@email) would be backfilled with customer ID @customer_id.', ['@uid' => $uid, '@email' => $email, '@customer_id' => $customerId]));
        }
      } catch (\Exception $e) {
        $this->logger()->error(dt('Failed to backfill user @uid (@email): @message', ['@uid' => $uid, '@email' => $email, '@message' => $e->getMessage()]));
      }

      $this->io()->progressAdvance();
    }

    $this->io()->progressFinish();
    $this->io()->success('Backfill complete.');
  }
}
