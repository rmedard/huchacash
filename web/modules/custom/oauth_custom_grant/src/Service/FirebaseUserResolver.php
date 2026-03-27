<?php

namespace Drupal\oauth_custom_grant\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\oauth_custom_grant\Form\FirebaseOtpSettingsForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves or creates a Drupal user account from a verified Firebase payload.
 */
class FirebaseUserResolver {

  protected EntityStorageInterface $userStorage;

  /**
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected $loggerFactory,
  ) {
    $this->userStorage = $this->entityTypeManager->getStorage('user');
  }

  protected function logger(): LoggerInterface {
    return $this->loggerFactory->get('FirebaseUserResolver');
  }

  /**
   * Finds an existing Drupal user or creates one from the Firebase token payload.
   *
   * Resolution order:
   *  1. Match by firebase_uid field (most reliable — handles phone number changes).
   *  2. Match by phone field (catches users registered before this module).
   *  3. Auto-create if enabled in config.
   *
   * @param array $firebasePayload
   *   The verified payload from FirebaseTokenVerifier::verify().
   *
   * @return UserInterface
   *
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function resolve(array $firebasePayload): UserInterface {
    $config      = $this->configFactory->get(FirebaseOtpSettingsForm::SETTINGS);
    $uidField    = $config->get('firebase_uid_field') ?: 'field_user_firebase_uid';
    $phoneField  = $config->get('phone_field') ?: 'field_user_phone_number';
    $autoCreate  = (bool) $config->get('auto_create_users');
    $defaultRole = $config->get('default_role') ?: 'authenticated';

    $uid   = $firebasePayload['uid'];
    $phone = $firebasePayload['phone_number'];
    $email = $firebasePayload['email'];

    // 1. Match by Firebase UID (strongest identifier).
    if ($this->fieldExists('user', $uidField)) {
      $accounts = $this->userStorage->loadByProperties([$uidField => $uid]);
      if ($accounts) {
        $account = reset($accounts);
        $this->logger()->debug('Firebase OTP: found user @id by UID field.', ['@id' => $account->id()]);
        return $account;
      }
    }

    // 2. Match by phone number.
    if ($phone && $this->fieldExists('user', $phoneField)) {
      $accounts = $this->userStorage->loadByProperties([$phoneField => $phone]);
      if ($accounts) {
        $account = reset($accounts);
        // Back-fill the Firebase UID so future logins hit path 1.
        if ($this->fieldExists('user', $uidField) && $account->get($uidField)->isEmpty()) {
          $account->set($uidField, $uid);
          $account->save();
        }
        $this->logger()->debug('Firebase OTP: found user @id by phone field.', ['@id' => $account->id()]);
        return $account;
      }
    }

    // 3. Match by email (for tokens that carry an email, e.g. email-link OTP).
    if ($email) {
      $accounts = $this->userStorage->loadByProperties(['mail' => $email]);
      if ($accounts) {
        $account = reset($accounts);
        $this->logger()->debug('Firebase OTP: found user @id by email.', ['@id' => $account->id()]);
        return $account;
      }
    }

    // 4. Auto-create.
    if (!$autoCreate) {
      throw new \RuntimeException('No Drupal user found for this Firebase identity and auto-creation is disabled.');
    }

    return $this->createUser($firebasePayload, $uidField, $phoneField, $defaultRole);
  }

  /**
   * Creates a new active Drupal user from a Firebase payload.
   */
  protected function createUser(array $payload, string $uidField, string $phoneField, string $defaultRole): UserInterface {
    $uid   = $payload['uid'];
    $phone = $payload['phone_number'];
    $email = $payload['email'];

    // Build a unique, human-readable username.
    $username = $this->generateUsername($phone, $email, $uid);

    $values = [
      'name'   => $username,
      'status' => 1,
    ];

    if ($email) {
      $values['mail'] = $email;
    }

    try {
      /** @var UserInterface $account */
      $account = $this->userStorage->create($values);

      if ($phone && $this->fieldExists('user', $phoneField)) {
        $account->set($phoneField, $phone);
      }

      if ($this->fieldExists('user', $uidField)) {
        $account->set($uidField, $uid);
      }

      if ($defaultRole !== 'authenticated') {
        $account->addRole($defaultRole);
      }

      $account->save();

      $this->createSkeletonCustomer($account, $phone);

      $this->logger()->info(
        'Firebase OTP: auto-created Drupal user @id (@name) for Firebase UID @uid.',
        ['@id' => $account->id(), '@name' => $username, '@uid' => $uid]
      );

      return $account;
    } catch (EntityStorageException $e) {
      $this->logger()->error($e->getMessage());
    }
    throw new \RuntimeException('Unable to create user.');
  }

  /**
   * Generates a unique username. Prefers phone, falls back to email or UID.
   */
  protected function generateUsername(?string $phone, ?string $email, string $uid): string {
    $base = $phone ?? ($email ? strstr($email, '@', TRUE) : 'user_' . substr($uid, 0, 8));
    // Ensure uniqueness by appending a counter if needed.
    $candidate = $base;
    $i = 1;
    while ($this->usernameExists($candidate)) {
      $candidate = $base . '_' . $i++;
    }
    return $candidate;
  }

  protected function usernameExists(string $name): bool {
    return (bool) $this->userStorage->loadByProperties(['name' => $name]);
  }

  /**
   * Checks whether a field exists on the user entity type.
   */
  protected function fieldExists(string $entityType, string $fieldName): bool {
    // Field names starting without 'field_' are base fields — always present.
    if (!str_starts_with($fieldName, 'field_')) {
      return TRUE;
    }
    $fieldStorageConfig = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load("$entityType.$fieldName");
    return $fieldStorageConfig !== NULL;
  }

  protected function createSkeletonCustomer(UserInterface $account, ?string $phone): void {
    try {
      $customer = Node::create([
        'type'                             => 'customer',
        'uid'                              => $account->id(),
        'status'                           => 0, // unpublished until profile complete
        'field_customer_user'              => $account->id(),
        'field_customer_phone'             => $phone,
        'field_customer_available_balance' => 0,
        'field_customer_pending_balance'   => 0,
        // firstname/lastname intentionally left empty
      ]);
      $customer->save();
    } catch (\Exception $e) {
      $this->logger()->error('Failed to create skeleton customer: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
