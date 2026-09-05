<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers;

use Grav\Plugin\Email\Providers\SendHeader;
use PHPUnit\Framework\TestCase;

/**
 * The one header name every provider answers, and the reading of it.
 *
 * Nothing here boots Grav, so the config path is exercised through
 * {@see SendHeader::override()} — which is the same decision made one step
 * earlier and is the path an add-on actually uses. What a booted site does with
 * `plugins.email.providers.send_header` is a config read and nothing more.
 */
final class SendHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        SendHeader::override(null);
    }

    protected function tearDown(): void
    {
        SendHeader::override(null);
    }

    public function testTheDefaultNameSaysNothingAboutWhoIsStampingIt(): void
    {
        self::assertSame('X-Grav-Send-Id', SendHeader::DEFAULT_NAME);
        self::assertSame('X-Grav-Send-Id', SendHeader::name());
    }

    public function testAnOverrideWinsForTheRestOfTheRequest(): void
    {
        SendHeader::override('X-Shop-Send');

        self::assertSame('X-Shop-Send', SendHeader::name());
    }

    public function testNullPutsTheDefaultBack(): void
    {
        SendHeader::override('X-Shop-Send');
        SendHeader::override(null);

        self::assertSame('X-Grav-Send-Id', SendHeader::name());
    }

    /**
     * A caller that hands over nonsense gets the working default rather than a
     * store whose mail is refused by the first server that reads it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('illegalNames')]
    public function testAnUnusableNameIsIgnored(string $name): void
    {
        SendHeader::override($name);

        self::assertSame('X-Grav-Send-Id', SendHeader::name());
    }

    /** @return array<string, array{0: string}> */
    public static function illegalNames(): array
    {
        return [
            'empty' => [''],
            'nothing but space' => ['   '],
            'a space inside it' => ['X Shop Send'],
            'a colon' => ['X-Shop-Send:'],
            'a newline' => ["X-Shop\r\nInjected: yes"],
            'a comma' => ['X-Shop,Send'],
            'too long' => [str_repeat('X', 200)],
        ];
    }

    public function testAnOverrideIsTrimmed(): void
    {
        SendHeader::override("  X-Shop-Send \n");

        self::assertSame('X-Shop-Send', SendHeader::name());
    }

    public function testTheMetadataKeyIsTheNameWithoutItsLeadingX(): void
    {
        self::assertSame('Grav-Send-Id', SendHeader::metadataKey());
        self::assertSame('X-PM-Metadata-Grav-Send-Id', SendHeader::metadataHeader());
    }

    public function testTheMetadataKeyIsCappedWherePostmarkCapsIt(): void
    {
        SendHeader::override('X-A-Very-Long-Header-Name-Indeed');

        self::assertSame(20, \strlen(SendHeader::metadataKey()));
        self::assertSame('A-Very-Long-Header-N', SendHeader::metadataKey());
    }

    public function testANameWithNoXPrefixKeepsAllOfItself(): void
    {
        SendHeader::override('Shop-Send');

        self::assertSame('Shop-Send', SendHeader::metadataKey());
    }

    // ------------------------------------------------------------ idFrom()

    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function testASendIdIsReadHoweverTheProviderTypedIt(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, SendHeader::idFrom($value));
    }

    /** @return array<string, array{0: mixed, 1: string|null}> */
    public static function values(): array
    {
        return [
            'a string' => ['41', '41'],
            'an int' => [41, '41'],
            'a whole float' => [41.0, '41'],
            'a fractional float' => [41.5, null],
            'a uuid' => ['0d5a1f7e-1b2c-4a3d-9e8f-000000000041', '0d5a1f7e-1b2c-4a3d-9e8f-000000000041'],
            'padded' => ['  41  ', '41'],
            'empty' => ['', null],
            'nothing but space' => ['   ', null],
            'null' => [null, null],
            'an array' => [['41'], null],
            'a boolean' => [true, null],
            'too long' => [str_repeat('4', 191), null],
        ];
    }

    public function testTheCapIsInclusive(): void
    {
        self::assertSame(str_repeat('4', 190), SendHeader::idFrom(str_repeat('4', 190)));
    }

    // -------------------------------------------------------------- idIn()

    public function testASendIdIsFoundInAMapUnderEitherSpelling(): void
    {
        self::assertSame('41', SendHeader::idIn(['X-Grav-Send-Id' => '41']));
        self::assertSame('41', SendHeader::idIn(['x-grav-send-id' => '41']));
    }

    public function testAMapIsSearchedForWhateverNameTheCallerAsksFor(): void
    {
        self::assertSame('41', SendHeader::idIn(['X-Shop-Send' => 41], 'X-Shop-Send'));
        self::assertNull(SendHeader::idIn(['X-Shop-Send' => 41]));
    }

    public function testAMapThatIsNotAMapAnswersNothing(): void
    {
        self::assertNull(SendHeader::idIn([]));
        self::assertNull(SendHeader::idIn('nope'));
        self::assertNull(SendHeader::idIn(null));
    }

    /**
     * Mailgun's `user-variables` is `[]` rather than `{}` when there are none,
     * which in PHP is a list where a map was expected.
     */
    public function testAnEmptyListWhereAMapWasExpectedAnswersNothing(): void
    {
        self::assertNull(SendHeader::idIn(['41']));
    }

    public function testAnEmptyValueUnderTheRightKeyDoesNotStopTheSearch(): void
    {
        self::assertSame('41', SendHeader::idIn([
            'X-Grav-Send-Id' => '  ',
            'x-grav-send-id' => '41',
        ]));
    }

    // ---------------------------------------------------------- idInList()

    public function testASendIdIsFoundInANameValueList(): void
    {
        $headers = [
            ['name' => 'Subject', 'value' => 'Hello'],
            ['name' => 'x-grav-send-id', 'value' => '41'],
        ];

        self::assertSame('41', SendHeader::idInList($headers));
    }

    public function testAListWithoutTheHeaderAnswersNothing(): void
    {
        self::assertNull(SendHeader::idInList([['name' => 'Subject', 'value' => 'Hello']]));
        self::assertNull(SendHeader::idInList('nope'));
    }

    public function testAnyHeaderCanBeReadOutOfTheSameList(): void
    {
        $headers = [
            ['name' => 'Message-ID', 'value' => '<abc@example.com>'],
            ['name' => 'X-Grav-Send-Id', 'value' => '41'],
        ];

        self::assertSame('<abc@example.com>', SendHeader::headerInList($headers, 'message-id'));
    }

    public function testARubbishEntryInAListIsSteppedOver(): void
    {
        $headers = [
            'not an entry',
            ['name' => 'X-Grav-Send-Id', 'value' => ''],
            ['name' => 'X-Grav-Send-Id', 'value' => 41],
        ];

        self::assertSame('41', SendHeader::idInList($headers));
    }

    // ---------------------------------------------------------- idInTags()

    public function testASendIdIsFoundInSesMessageTags(): void
    {
        self::assertSame('41', SendHeader::idInTags(['X-Grav-Send-Id' => ['41']]));
        self::assertSame('41', SendHeader::idInTags(['x-grav-send-id' => '41']));
    }

    public function testTagsThatAreNotTagsAnswerNothing(): void
    {
        self::assertNull(SendHeader::idInTags([]));
        self::assertNull(SendHeader::idInTags('nope'));
        self::assertNull(SendHeader::idInTags([['41']]));
    }

    public function testTagsAreSearchedForWhateverNameTheCallerAsksFor(): void
    {
        self::assertSame('41', SendHeader::idInTags(['X-Shop-Send' => ['41']], 'X-Shop-Send'));
    }

    /** An override reaches every reader, not only {@see SendHeader::name()}. */
    public function testAnOverrideChangesWhatTheReadersLookFor(): void
    {
        SendHeader::override('X-Shop-Send');

        self::assertSame('41', SendHeader::idIn(['X-Shop-Send' => '41']));
        self::assertSame('41', SendHeader::idInList([['name' => 'X-Shop-Send', 'value' => '41']]));
        self::assertSame('41', SendHeader::idInTags(['X-Shop-Send' => ['41']]));
        self::assertNull(SendHeader::idIn(['X-Grav-Send-Id' => '41']));
    }
}
