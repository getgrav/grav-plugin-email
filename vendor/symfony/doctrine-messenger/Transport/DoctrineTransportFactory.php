<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Doctrine\Transport;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ConnectionRegistry;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @author Vincent Touzet <vincent.touzet@gmail.com>
 */
class DoctrineTransportFactory implements TransportFactoryInterface
{
    private $registry;

    public function __construct($registry)
    {
        if (!$registry instanceof RegistryInterface && !$registry instanceof ConnectionRegistry) {
            throw new \TypeError(sprintf('Expected an instance of "%s" or "%s", but got "%s".', RegistryInterface::class, ConnectionRegistry::class, get_debug_type($registry)));
        }

        $this->registry = $registry;
    }

    /**
     * @param array $options You can set 'use_notify' to false to not use LISTEN/NOTIFY with postgresql
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $useNotify = ($options['use_notify'] ?? true);
        unset($options['transport_name'], $options['use_notify']);
        // Always allow PostgreSQL-specific keys, to be able to transparently fallback to the native driver when LISTEN/NOTIFY isn't available
        $configuration = PostgreSqlConnection::buildConfiguration($dsn, $options);

        try {
            $driverConnection = $this->registry->getConnection($configuration['connection']);
        } catch (\InvalidArgumentException $e) {
            throw new TransportException('Could not find Doctrine connection from Messenger DSN.', 0, $e);
        }

        if ($useNotify && $driverConnection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection = new PostgreSqlConnection($configuration, $driverConnection);
        } else {
            $connection = new Connection($configuration, $driverConnection);
        }

        return new DoctrineTransport($connection, $serializer);
    }

    public function supports(string $dsn, array $options): bool
    {
        return 0 === strpos($dsn, 'doctrine://');
    }
}

if (!class_exists(\Symfony\Component\Messenger\Transport\Doctrine\DoctrineTransportFactory::class, false)) {
    class_alias(DoctrineTransportFactory::class, \Symfony\Component\Messenger\Transport\Doctrine\DoctrineTransportFactory::class);
}
