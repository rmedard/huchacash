<?php

namespace Drupal\dinger_settings\Controller;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SocialAuthController extends ControllerBase
{
  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Enable or disable debugging.
   *
   * @var bool
   */
  protected bool $debug = FALSE;

  /**
   * Secret to compare against a passed token.
   *
   * Implementing https://www.drupal.org/project/key
   * is a stronger approach.
   * In this example, you would need $config['dinger_settings']['token'] = 'yourtokeninsettingsphp'; in settings.php.
   *
   * @var string
   */
  protected string $secret;

  public function __construct(LoggerChannelFactory $logger, ConfigFactory $configFactory)
  {
    $this->logger = $logger->get('SocialAuthController');
    $this->secret = $configFactory->get('dinger_settings')->get('callback_token');
  }

  public static function create(ContainerInterface $container): SocialAuthController
  {
    return new SocialAuthController(
      $container->get('logger.factory'),
      $container->get('config.factory')
    );
  }

  /**
   * @throws EntityStorageException|GuzzleException
   */
  public function capture(Request $request): JsonResponse
  {
    if ($request->request->count() === 0) {
      $message = 'The payload was empty.';
      $this->logger->error($message);
      return new JsonResponse(['message' => $message], Response::HTTP_BAD_REQUEST);
    }
    $id_token = $request->request->get('id_token');

    $data = $this->verifyGoogleSignInToken($id_token);
    if ($data['email_verified'] === true) {
      $email = $data['email'];
      $user = user_load_by_mail($email);
      if (!$user instanceof UserInterface) {
        $photo_url = $data['picture'];
        $first_name = $data['given_name'];
        $last_name = $data['family_name'];

        $user = User::create();
        $user->setUsername($email); //This username must be unique and accept only a-Z,0-9, - _ @ .
        $user->setPassword("azerty");
        $user->enforceIsNew();
        $user->setEmail($email);
        $user->addRole('customer');
        $user->activate();
        $result = $user->save();
        if ($result === SAVED_NEW) {
          Node::create([
            'type' => 'customer',
            'title' => 'New Customer',
            'field_customer_lastname' => $last_name,
            'field_customer_user' => $user->id()
          ])->save();
        }
      }
      return new JsonResponse(['message' => 'Google signed in successfully'], Response::HTTP_OK);
    } else {
      return new JsonResponse(['message' => 'Google sign in verification failed'], Response::HTTP_BAD_REQUEST);
    }
  }

  /**
   * Simple authorization using a token.
   *
   * @param string $token
   *    A random token only your webhook knows about.
   *
   * @return AccessResult
   *   AccessResult allowed or forbidden.
   */
  public function authorize(string $token): AccessResult
  {
    if ($token === $this->secret) {
      $this->logger->info('Social auth endpoint request accepted');
      return AccessResult::allowed();
    }
    $this->logger->error('Social auth endpoint request rejected');
    return AccessResult::forbidden();
  }

  /**
   * @throws GuzzleException
   */
  private function verifyGoogleSignInToken($token): array
  {
    $url = $this->configFactory->get('dinger_settings')->get('google_token_verification_url');
    $response = Drupal::httpClient()->get($url, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    if ($response->getStatusCode() == Response::HTTP_OK) {
      $this->logger->info('Google Sign In token verified successfully');
      $data = (array)Json::decode($response->getBody());
      return [
        'sub' => $data['sub'],
        'name' => $data['name'],
        'given_name' => $data['given_name'],
        'family_name' => $data['family_name'],
        'picture' => $data['picture'],
        'email' => $data['email'],
        'email_verified' => $data['email_verified'],
        'locale' => $data['locale'],
      ];
    }
    $this->logger->error('Google Sign In verification failed');
    return [];
  }
}
