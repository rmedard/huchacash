<?php

namespace Drupal\oauth_custom_grant\Repositories;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Drupal\simple_oauth\Entities\UserEntity;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use GuzzleHttp\Exception\RequestException;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

class UserRepository implements UserRepositoryInterface
{

  /**
   * @var UserAuthInterface
   */
  protected UserAuthInterface $userAuth;

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @param UserAuthInterface $userAuth
   * @param LoggerChannelFactory $logger
   */
  public function __construct(UserAuthInterface $userAuth, LoggerChannelFactory $logger)
  {
    $this->userAuth = $userAuth;
    $this->logger = $logger->get('google_sign_in');
  }

  /**
   * @param $token
   * @param $email
   * @param $grantType
   * @param ClientEntityInterface $clientEntity
   * @return UserEntity|null
   */
  public function getUserEntityByUserCredentials($token, $email, $grantType, ClientEntityInterface $clientEntity): ?UserEntity
  {
    $UserEntity = new UserEntity();

    try {
      $url = Drupal::service('config.factory')->get('dinger_settings')->get('google_token_verification_url');
      $response = Drupal::httpClient()->get($url, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
      if ($response->getStatusCode() == Response::HTTP_OK) {
        $this->logger->info('Token verified successfully');
        $data = (array)Json::decode($response->getBody());
        if ($email !== $data['email']) {
          $this->logger->error('Mismatching user details');
          return null;
        }

        //Check if user exist.
        $user = user_load_by_mail($email);
        if ($user) {
          $this->logger->info('Loaded user from DB');
          $UserEntity->setIdentifier($user->id());
        } else {
          $this->logger->info('Creating new user');
          $newUserId = $this->createNewCustomer(userData: $data);
          $UserEntity->setIdentifier($newUserId);
        }
        return $UserEntity;
      }
      $this->logger->error('Token verification failed');
    } catch (RequestException $e) {
      $this->logger->error('User validation failed: ' . $e->getMessage());
    } catch (EntityStorageException $e) {
      $this->logger->error('Creating new user failed: ' . $e->getMessage());
    }
    return null;
  }

  /**
   * @throws EntityStorageException
   */
  private function createNewCustomer($userData): int
  {
    Drupal::logger('MeResource')->info('New customer: <pre><code>' . print_r($userData, TRUE) . '</code></pre>');
    $sub = $userData['sub'];
    $name = $userData['name'];
    $given_name = $userData['given_name'];
    $family_name = $userData['family_name'];
    $picture = $userData['picture'];
    $email = $userData['email'];
    $email_verified = $userData['email_verified'];
    $locale = $userData['locale'];

    $newUser = User::create();
    $newUser->setEmail($email);
    $newUser->setUsername($email);
    $newUser->setPassword('azerty');
    $newUser->addRole('customer');
    $newUser->setValidationRequired(false);
    $newUser->set('user_picture', $picture);
    $newUser->activate();
    $userSaved = $newUser->save();
    if ($userSaved === SAVED_NEW) {
      $newCustomer = Node::create([
        'type' => 'customer',
        'uid' => $newUser->id(),
        'field_customer_firstname' => $given_name,
        'field_customer_lastname' => $family_name,
        'field_customer_user' => $newUser->id(),
        'field_customer_available_balance' => 0,
        'field_customer_pending_balance' => 0
      ]);
      $newCustomer->save();
    } else {
      $this->logger->error('Creating new customer from Gmail failed');
    }
    return $newUser->id();
  }
}
