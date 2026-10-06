<?php

namespace Farysasyraf\SavedRoutes\Tests\Unit;

use Farysasyraf\SavedRoutes\Parameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParametersTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function typed(): array
    {
        return [
            'one name' => ['id', 'id'],
            'joined by /' => ['id/kw', 'id/kw'],
            'with commas and spaces' => ['id, kw', 'id/kw'],
            'as placeholders' => ['{id}/{kw}', 'id/kw'],
            'optional last' => ['id/kw?', 'id/kw?'],
            'nothing' => ['  ', null],
            'not a name' => ['id/k-w', null],
            'starting with a digit' => ['1id', null],
            'used twice' => ['id/ID', null],
            'required after optional' => ['id?/kw', null],
            'longer than the router allows' => [str_repeat('a', 33), null],
        ];
    }

    #[DataProvider('typed')]
    public function test_parse_tidies_what_was_typed(string $typed, ?string $expected): void
    {
        $this->assertSame($expected, Parameters::parse($typed));
    }

    public function test_uri_puts_a_placeholder_after_the_path_for_each_name(): void
    {
        $this->assertSame('reports', Parameters::uri('reports', null));
        $this->assertSame('reports/{id}/{kw?}', Parameters::uri('reports', 'id/kw?'));
    }

    public function test_all_optional_is_true_only_when_the_bare_path_opens_it(): void
    {
        $this->assertTrue(Parameters::allOptional(null));
        $this->assertTrue(Parameters::allOptional('month?'));
        $this->assertFalse(Parameters::allOptional('id/kw?'));
    }
}
