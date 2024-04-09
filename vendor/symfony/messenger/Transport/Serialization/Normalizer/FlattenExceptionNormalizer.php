<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Transport\Serialization\Normalizer;

use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\ContextAwareNormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * This normalizer is only used in Debug/Dev/Messenger contexts.
 *
 * @author Pascal Luna <skalpa@zetareticuli.org>
 */
final class FlattenExceptionNormalizer implements DenormalizerInterface, ContextAwareNormalizerInterface
{
    use NormalizerAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException
     */
    public function normalize($object, ?string $format = null, array $context = []): array
    {
        $normalized = [
            'message' => $object->getMessage(),
            'code' => $object->getCode(),
            'headers' => $object->getHeaders(),
            'class' => $object->getClass(),
            'file' => $object->getFile(),
            'line' => $object->getLine(),
            'previous' => null === $object->getPrevious() ? null : $this->normalize($object->getPrevious(), $format, $context),
            'status' => $object->getStatusCode(),
            'status_text' => $object->getStatusText(),
            'trace' => $object->getTrace(),
            'trace_as_string' => $object->getTraceAsString(),
        ];

        return $normalized;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof FlattenException && ($context[Serializer::MESSENGER_SERIALIZATION_CONTEXT] ?? false);
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize($data, string $type, ?string $format = null, array $context = []): FlattenException
    {
        $object = new FlattenException();

        $object->setMessage($data['message']);
        $object->setCode($data['code']);
        $object->setStatusCode($data['status'] ?? 500);
        $object->setClass($data['class']);
        $object->setFile($data['file']);
        $object->setLine($data['line']);
        $object->setStatusText($data['status_text']);
        $object->setHeaders((array) $data['headers']);

        if (isset($data['previous'])) {
            $object->setPrevious($this->denormalize($data['previous'], $type, $format, $context));
        }

        $property = new \ReflectionProperty(FlattenException::class, 'trace');
        $property->setAccessible(true);
        $property->setValue($object, (array) $data['trace']);

        $property = new \ReflectionProperty(FlattenException::class, 'traceAsString');
        $property->setAccessible(true);
        $property->setValue($object, $data['trace_as_string']);

        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, string $type, ?string $format = null, array $context = []): bool
    {
        return FlattenException::class === $type && ($context[Serializer::MESSENGER_SERIALIZATION_CONTEXT] ?? false);
    }
}
