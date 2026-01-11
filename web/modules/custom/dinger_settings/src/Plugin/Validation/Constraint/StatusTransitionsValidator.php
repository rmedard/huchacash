<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\dinger_settings\Utils\StatusBaseInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class StatusTransitionsValidator extends ConstraintValidator implements ContainerInjectionInterface
{
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * @param mixed $value
   * @param StatusTransitionConstraintBase $constraint
   * @return void
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function validate(mixed $value, Constraint $constraint): void
  {
    /** @var FieldItemListInterface $value */
    if (!isset($value)) {
      return;
    }

    $entity = $value->getEntity();
    $newStatus = $value->getString();

    /** @var StatusBaseInterface $enumClass */
    $enumClass = $constraint->statusEnumClass();
    $newStatusType = $enumClass::fromString($newStatus);

    if ($entity->isNew()) {
      if (!in_array($newStatusType, $enumClass::entryPoints(), true)) {
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
      $this->context->addViolation($constraint->message, [
        '@from' => $oldStatus,
        '@to' => $newStatus,
      ]);
    }
  }
}
