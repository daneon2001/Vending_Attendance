<?php

namespace App\Services\FieldIdentity;

use InvalidArgumentException;

final class MexicanPhone
{
    public static function normalize(string $input): string
    {
        $value = preg_replace('/[ ()-]/', '', trim($input));
        if (preg_match('/^[0-9]{10}$/D', $value)) {
            $value = '+52'.$value;
        }
        if (! preg_match('/^\\+52[0-9]{10}$/D', $value)) {
            throw new InvalidArgumentException('A Mexican E.164 phone is required.');
        }

        return $value;
    }

    public static function masked(string $phone): string
    {
        return '******'.substr(self::normalize($phone), -4);
    }
}
