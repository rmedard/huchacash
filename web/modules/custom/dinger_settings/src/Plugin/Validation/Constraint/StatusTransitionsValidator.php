<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dinger_settings\Utils\StatusBaseInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class StatusTransitionsValidator extends ConstraintValidator implements ContainerInjectionInterface
{
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerChannelFactory
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
    );
  }

  /**
   * @param FieldItemListInterface $value
   * @param StatusTransitionConstraintBase $constraint
   * @return void
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function validate(mixed $value, Constraint $constraint): void
  {
    $logger = $this->loggerChannelFactory->get('StatusTransitionsValidator');

    if ($value->isEmpty()) {
      $logger->debug("Value is empty");
    }

    if (!isset($value)) {
      return;
    } else {
      $logger->debug("Value is empty. Sana");
    }

    $entity = $value->getEntity();
    $newStatus = $value->getString();

    /** @var StatusBaseInterface $enumClass */
    $enumClass = $constraint->statusEnumClass();
    $newStatusType = $enumClass::fromString($newStatus);

    if ($entity->isNew()) {
      if (!in_array($newStatusType, $enumClass::entryPoints(), true)) {
        $logger->warning('For @type => Status @status is not a valid entry point', [
          '@type' => $entity->bundle(),
          '@status' => $newStatusType->toString()
        ]);
        $this->context->buildViolation($constraint->invalidInitialStatusMessage, [
          '@bundle' => $entity->bundle(),
          '@status' => $enumClass::entryPointsString(),
        ])->addViolation();
      }
      return;
    }

    /** @var Node $original */
    $original = $entity->original
      ?? $this->entityTypeManager
        ->getStorage($entity->getEntityTypeId())
        ->loadUnchanged($entity->id());

    if (!$original) {
      return;
    }

    $oldStatus = $original->get($value->getName())->getString();
    $oldStatusType = $enumClass::fromString($oldStatus);

    if ($oldStatusType === $newStatusType) {
      return;
    }

    $transitions = $constraint->allowedTransitions()[$oldStatus] ?? null;
    if (empty($transitions) || !in_array($newStatusType, $transitions, true)) {
      $logger->warning('For @type => Status @oldStatus cannot transition to @newStatus', [
        '@type' => $entity->bundle(),
        '@oldStatus' => $oldStatus,
        '@newStatus' => $newStatusType->toString()
      ]);

      $this->context->addViolation($constraint->message, [
        '@from' => $oldStatus,
        '@to' => $newStatus,
      ]);
    }
  }
}
