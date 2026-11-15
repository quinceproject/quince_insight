<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Insight\Core;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Minimalny kontener dla kodu dzialajacego poza panelem (front, hooki, cron).
 *
 * Tam nie ma kontenera Symfony, a klasy logiki sa te same co w panelu. Zasada modulu:
 * konstruktor klasy logiki przyjmuje wylacznie inne klasy modulu — dzieki temu da sie
 * je zbudowac sama refleksja, bez pliku konfiguracyjnego.
 */
final class Container
{
    /** @var array<string,object> */
    private static array $instances = [];

    /** @var array<string,bool> klasy w trakcie budowy — wykrycie zaleznosci w kolko */
    private static array $building = [];

    /**
     * Buduje obiekt z zaleznosciami przez refleksje konstruktora; jedna instancja na zadanie.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function get(string $class): object
    {
        if (isset(self::$instances[$class])) {
            return self::$instances[$class];
        }

        if (!class_exists($class)) {
            throw new \LogicException('Quince Insight: class ' . $class . ' does not exist.');
        }

        if (isset(self::$building[$class])) {
            throw new \LogicException('Quince Insight: circular dependency while building ' . $class . '.');
        }

        self::$building[$class] = true;

        try {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();
            $arguments = [];

            foreach ($constructor ? $constructor->getParameters() : [] as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $arguments[] = self::get($type->getName());

                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }

                throw new \LogicException(
                    'Quince Insight: ' . $class . '::__construct($' . $parameter->getName()
                    . ') is not a module class. Logic classes may depend only on other module classes.'
                );
            }

            $instance = $reflection->newInstanceArgs($arguments);
        } finally {
            unset(self::$building[$class]);
        }

        return self::$instances[$class] = $instance;
    }

    /** Podmienia instancje (testy) albo wstawia gotowy obiekt zbudowany gdzie indziej. */
    public static function set(string $class, object $instance): void
    {
        self::$instances[$class] = $instance;
    }

    /** Czysci instancje — testy oraz cron po przelaczeniu sklepu. */
    public static function reset(): void
    {
        self::$instances = [];
    }
}
