<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\ProviderResponse;
use App\Provider\ProviderResponseException;
use PHPUnit\Framework\TestCase;

final class ProviderResponseTest extends TestCase
{
    public function testDecodesPlainJson(): void
    {
        $response = new ProviderResponse('stub', 'm', '{"a":1}');

        self::assertSame(['a' => 1], $response->json());
    }

    public function testDecodesMarkdownFence(): void
    {
        $response = new ProviderResponse('stub', 'm', "```json\n{\"a\":1}\n```");

        self::assertSame(['a' => 1], $response->json());
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(ProviderResponseException::class);
        (new ProviderResponse('stub', 'm', 'pas du json'))->json();
    }

    public function testRejectsJsonList(): void
    {
        $this->expectException(ProviderResponseException::class);
        (new ProviderResponse('stub', 'm', '[1,2]'))->json();
    }
}
