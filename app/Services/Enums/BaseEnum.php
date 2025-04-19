<?php

namespace App\Services\Enums;

use Exception;
use ReflectionClass;

abstract class BaseEnum {
    /**
     * Get all constant keys of the class.
     *
     * @return array An array of constant keys.
     */
    public static function getAllKeys(): array 
    {
        $reflect = new ReflectionClass(static::class);
        return array_keys($reflect->getConstants());
    }

    /**
     * Get all constant values of the class.
     *
     * @return array An array of constant values.
     */
    public static function getAllValues(): array 
    {
        $reflect = new ReflectionClass(static::class);
        return array_values($reflect->getConstants());
    }

    /**
     * Get a constant id value of the class by name
     *
     * @param string $name
     * @return int
     */
    public static function getIdByName(string $name): int 
    {
        $reflect = new ReflectionClass(static::class);
        $keys_name = array_keys($reflect->getConstants());

        $constant_index = null;
        foreach($keys_name AS $index => $key_name) {
            if($key_name !== strtoupper($name) . '_ID') {
                continue;
            }

            $constant_index = $index;
        }

        if(is_null($constant_index)) {
            throw new Exception('Constant ' . strtoupper($name) . '_ID not found');
        }

        $values = array_values($reflect->getConstants());
        return $values[$constant_index];
    }

    /**
     * Get a constant name value of the class by id
     *
     * @param int $id
     * @return ?string
     */
    public static function getNameById(int $id): ?string 
    {
        $reflect = new ReflectionClass(static::class);
        $values = array_values($reflect->getConstants());

        foreach($values AS $index => $value) {
            if($value === $id) {
                return $values[$index - 1];
            }
        }

        return null;
    }
}
