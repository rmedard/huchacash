<?php

namespace Drupal\dinger_settings\Controller;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SocialAuthController extends ControllerBase
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

  public function __construct(LoggerChannelFactory $logger)
  {
    $this->logger = $logger->get('dinger_settings');
    $this->secret = Drupal::service('config.factory')->get('dinger_settings')->get('token');
  }

  public static function create(ContainerInterface $container): StripeController|static
  {
    return new static(
      $container->get('logger.factory')
    );
  }

  public function capture(Request $request): Response
  {
    $response = new Response();
    if ($request->request->count() === 0) {
      $message = 'The payload was empty.';
      $this->logger->error($message);
      $response->setContent($message);
      $response->setStatusCode(Response::HTTP_BAD_REQUEST);
      return $response;
    }
    $id_token = $request->request->get('id_token');

    $data = $this->verifyGoogleSignInToken($id_token);
    if ($data['email_verified'] === true) {
      $email = $data['email'];
      $user = user_load_by_mail($email);
      if ($user instanceof UserInterface) {

      } else {
        $photo_url = $data['picture'];
        $first_name = $data['given_name'];
        $last_name = $data['family_name'];

      }
      $response->setContent('Google signed in successfully');
      $response->setStatusCode(Response::HTTP_OK);
    } else {
      $response->setContent('Google sign in verification failed');
      $response->setStatusCode(Response::HTTP_BAD_REQUEST);
    }
    return $response;
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

  private function verifyGoogleSignInToken($token): array
  {
    $url = Drupal::service('config.factory')->get('dinger_settings')->get('google_token_verification_url');
    $response = Drupal::httpClient()->get($url, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    if ($response->getStatusCode() == Response::HTTP_OK) {
      $this->logger->info('Google Sign In token verified successfully');
      $data = (array) Json::decode($response->getBody());
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
