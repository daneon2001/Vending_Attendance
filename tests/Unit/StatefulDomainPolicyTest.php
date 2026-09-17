<?php

namespace Tests\Unit;

use App\Support\StatefulDomainPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatefulDomainPolicyTest extends TestCase
{
    public static function domains(): iterable
    {
        foreach (['*', '*.example.test', 'foo*.example.test', 'api.*.example.test', 'example.*',
            'localhost:8000,*', 'example.test,*.evil.test', 'https://example.test',
            'example.test/path', 'user@example.test', 'example.test?x=1', 'example.test#x',
            'example.test:0', 'example.test:65536', 'example.test:abc', 'bad host',
            'example.test\\path', '__SANCTUM_CURRENT_REQUEST_HOST__', 'foo?.example.test',
            '[ab].example.test', '127.0.0.999'] as $value) {
            yield $value => [explode(',', $value), false];
        }
        foreach (['example.test', 'example.test:8443', 'localhost', 'localhost:8000',
            '127.0.0.1:8000', '::1', 'example.test,localhost:8000',
            ' example.test , localhost:8000 ', 'example.test,example.test', ''] as $value) {
            yield 'valid '.$value => [explode(',', $value), true];
        }
        yield 'empty array' => [[], true];
        yield 'raw string is not effective array' => ['example.test', false];
        yield 'null' => [null, false];
        yield 'non string entry' => [[123], false];
        yield 'nested array' => [[['example.test']], false];
    }

    #[DataProvider('domains')]
    public function test_effective_configuration_is_validated_without_mutation(mixed $domains, bool $valid): void
    {
        $before = $domains;
        $this->assertSame($valid, StatefulDomainPolicy::isValid($domains));
        $this->assertSame($before, $domains);
    }
}
