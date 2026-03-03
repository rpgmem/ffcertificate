<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DataSanitizer;

/**
 * Tests for DataSanitizer: recursive data sanitization and
 * Brazilian name normalization.
 *
 * @covers \FreeFormCertificate\Core\DataSanitizer
 */
class DataSanitizerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub sanitize_key: mirrors WP core behaviour (lowercase, strip non-alphanumeric)
        Functions\when('sanitize_key')->alias(function ($key) {
            return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $key));
        });

        // Stub wp_kses: for unit testing purposes, strip HTML tags
        Functions\when('wp_kses')->alias(function ($data, $allowed_tags) {
            return strip_tags($data);
        });

        // Mock Utils::get_allowed_html_tags() — called by recursive_sanitize
        $utils_mock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utils_mock->shouldReceive('get_allowed_html_tags')
            ->andReturn(array(
                'b'      => array(),
                'strong' => array(),
                'i'      => array(),
                'em'     => array(),
            ));
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // recursive_sanitize — string inputs
    // ==================================================================

    public function test_recursive_sanitize_string_gets_sanitized(): void {
        $result = DataSanitizer::recursive_sanitize('hello world');
        $this->assertSame('hello world', $result);
    }

    public function test_recursive_sanitize_string_strips_html_tags(): void {
        $result = DataSanitizer::recursive_sanitize('<script>alert("xss")</script>');
        $this->assertSame('alert("xss")', $result);
    }

    public function test_recursive_sanitize_empty_string_returns_empty(): void {
        $result = DataSanitizer::recursive_sanitize('');
        $this->assertSame('', $result);
    }

    // ==================================================================
    // recursive_sanitize — array inputs
    // ==================================================================

    public function test_recursive_sanitize_array_sanitizes_keys_and_values(): void {
        $input = array(
            'First_Name' => 'Alice',
            'Last-Name'  => 'Smith',
        );

        $result = DataSanitizer::recursive_sanitize($input);

        // Keys should be lowercased via sanitize_key
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last-name', $result);
        $this->assertSame('Alice', $result['first_name']);
        $this->assertSame('Smith', $result['last-name']);
    }

    public function test_recursive_sanitize_array_strips_invalid_key_chars(): void {
        $input = array(
            'Key With Spaces!' => 'value',
        );

        $result = DataSanitizer::recursive_sanitize($input);

        // sanitize_key strips spaces and special chars
        $this->assertArrayHasKey('keywithspaces', $result);
        $this->assertSame('value', $result['keywithspaces']);
    }

    public function test_recursive_sanitize_nested_arrays(): void {
        $input = array(
            'Parent_Key' => array(
                'Child_Key' => 'nested value',
            ),
        );

        $result = DataSanitizer::recursive_sanitize($input);

        $this->assertArrayHasKey('parent_key', $result);
        $this->assertIsArray($result['parent_key']);
        $this->assertArrayHasKey('child_key', $result['parent_key']);
        $this->assertSame('nested value', $result['parent_key']['child_key']);
    }

    public function test_recursive_sanitize_deeply_nested_arrays(): void {
        $input = array(
            'Level1' => array(
                'Level2' => array(
                    'Level3' => 'deep value',
                ),
            ),
        );

        $result = DataSanitizer::recursive_sanitize($input);

        $this->assertSame('deep value', $result['level1']['level2']['level3']);
    }

    public function test_recursive_sanitize_empty_array_returns_empty_array(): void {
        $result = DataSanitizer::recursive_sanitize(array());
        $this->assertSame(array(), $result);
    }

    public function test_recursive_sanitize_array_with_html_in_values(): void {
        $input = array(
            'comment' => '<p>Hello <script>bad</script></p>',
        );

        $result = DataSanitizer::recursive_sanitize($input);

        $this->assertSame('Hello bad', $result['comment']);
    }

    public function test_recursive_sanitize_mixed_array_string_and_nested(): void {
        $input = array(
            'Name'    => 'John',
            'Address' => array(
                'Street' => '123 Main St',
                'City'   => 'Sao Paulo',
            ),
        );

        $result = DataSanitizer::recursive_sanitize($input);

        $this->assertSame('John', $result['name']);
        $this->assertSame('123 Main St', $result['address']['street']);
        $this->assertSame('Sao Paulo', $result['address']['city']);
    }

    // ==================================================================
    // normalize_brazilian_name — data provider
    // ==================================================================

    /**
     * @dataProvider brazilian_name_provider
     */
    public function test_normalize_brazilian_name(string $input, string $expected): void {
        $this->assertSame($expected, DataSanitizer::normalize_brazilian_name($input));
    }

    public static function brazilian_name_provider(): array {
        return [
            'all uppercase with da'              => ['ALEX PEREIRA DA SILVA', 'Alex Pereira da Silva'],
            'all lowercase with dos and e'       => ['maria dos santos e oliveira', 'Maria dos Santos e Oliveira'],
            'uppercase with de'                  => ['JOAO DE SOUZA FILHO', 'Joao de Souza Filho'],
            'connective das'                     => ['ANA DAS GRACAS', 'Ana das Gracas'],
            'connective do'                      => ['PEDRO DO CARMO', 'Pedro do Carmo'],
            'connective di'                      => ['MARCOS DI CAVALCANTI', 'Marcos di Cavalcanti'],
            'connective du'                      => ['JEAN DU PONT', 'Jean du Pont'],
            'multiple connectives'               => ['CARLOS DA SILVA DOS SANTOS', 'Carlos da Silva dos Santos'],
            'first word is connective'           => ['DA SILVA PEREIRA', 'Da Silva Pereira'],
            'first word is connective de'        => ['DE OLIVEIRA JUNIOR', 'De Oliveira Junior'],
            'first word is connective e'         => ['E SILVA', 'E Silva'],
            'empty string'                       => ['', ''],
            'single word name'                   => ['MARIA', 'Maria'],
            'single lowercase word'              => ['joao', 'Joao'],
            'accented characters uppercase'      => ['JOSE DA CONCEICAO', 'Jose da Conceicao'],
            'accented characters with tildes'    => ['JOAO DA ASSUNCAO', 'Joao da Assuncao'],
            'multiple spaces between words'      => ['ALEX   PEREIRA   DA   SILVA', 'Alex Pereira da Silva'],
            'leading and trailing spaces'        => ['  MARIA DOS SANTOS  ', 'Maria dos Santos'],
            'mixed case input'                   => ['aLeX pErEiRa Da SiLvA', 'Alex Pereira da Silva'],
            'all connectives'                    => ['DA DAS DE DO DOS E DI DU', 'Da das de do dos e di du'],
        ];
    }

    // ==================================================================
    // normalize_brazilian_name — additional edge cases
    // ==================================================================

    public function test_normalize_brazilian_name_preserves_utf8_accents(): void {
        $result = DataSanitizer::normalize_brazilian_name('JOAO CONCEICAO');
        $this->assertSame('Joao Conceicao', $result);
    }

    public function test_normalize_brazilian_name_handles_cedilla(): void {
        // c with cedilla
        $result = DataSanitizer::normalize_brazilian_name("GONCALVES");
        $this->assertSame('Goncalves', $result);
    }

    public function test_normalize_brazilian_name_two_word_name_with_connective(): void {
        $result = DataSanitizer::normalize_brazilian_name('SILVA E SOUZA');
        $this->assertSame('Silva e Souza', $result);
    }

    public function test_normalize_brazilian_name_tabs_and_newlines_not_in_output(): void {
        // preg_split on \s+ handles tabs
        $result = DataSanitizer::normalize_brazilian_name("ALEX\tPEREIRA\tDA\tSILVA");
        $this->assertSame('Alex Pereira da Silva', $result);
    }
}
