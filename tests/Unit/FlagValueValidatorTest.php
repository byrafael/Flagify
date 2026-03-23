<?php

declare(strict_types=1);

namespace Flagify\Tests\Unit;

use Flagify\Service\FlagValueValidator;
use Flagify\Support\ApiError;
use PHPUnit\Framework\TestCase;

final class FlagValueValidatorTest extends TestCase
{
    public function testBooleanFlagValidationAcceptsBooleans(): void
    {
        $validator = new FlagValueValidator();
        $validator->validateFlag('boolean', true, null);

        $this->assertTrue(true);
    }

    public function testSelectFlagRejectsInvalidDefault(): void
    {
        $this->expectException(ApiError::class);

        $validator = new FlagValueValidator();
        $validator->validateFlag('select', 'staging', ['prod', 'dev']);
    }

    public function testMultiSelectRejectsDuplicates(): void
    {
        $this->expectException(ApiError::class);

        $validator = new FlagValueValidator();
        $validator->validateValue('multi_select', ['us', 'us'], ['us', 'ca']);
    }
}
