<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;

/**
 * As duas chaves derivadas das constantes do WordPress sao calculadas no
 * maximo UMA vez por processo (#1230).
 *
 * Cada uma custa um `hash_pbkdf2( 'sha256', …, 10000, … )` -- medido em
 * 11,56 ms neste ambiente -- e `encrypt()` / `decrypt_internal()` pedem as
 * DUAS por chamada. Sem memoizacao, um decrypt custava 23,1 ms de CPU pura.
 *
 * COMO ESTE TESTE PROVA, E POR QUE NAO PROVA DE OUTRO JEITO
 *
 * Nao ha contador a inspecionar: `hash_pbkdf2` e uma funcao interna do PHP e
 * o Patchwork nao a instrumenta. Sobra o tempo -- e tempo e uma asserção
 * fragil a menos que a diferenca seja enorme. Aqui ela e: 11,56 ms contra
 * uma leitura de propriedade, uma razao na casa dos milhares. O piso exigido
 * abaixo e deliberadamente frouxo (20x), bem longe do ruido de um CI
 * carregado, e ainda assim impossivel de passar sem a memoizacao.
 *
 * O memo e limpo por REFLEXAO, e nao por um metodo de reset em producao. Um
 * `reset_keys()` publico existiria so para o teste, e seria exatamente a
 * indirecao que nao estreita nada de que o CLAUDE.md fala -- pior, seria uma
 * porta para invalidar em runtime um valor que, por construcao, nunca muda.
 *
 * SEM ISSO O TESTE SERIA DEPENDENTE DE ORDEM, que e a classe de defeito que
 * o proprio CLAUDE.md registra: se qualquer teste anterior no processo ja
 * tiver tocado `Encryption`, o memo ja esta quente e as duas medicoes saem
 * rapidas -- verde sem provar nada.
 *
 * @covers \FreeFormCertificate\Core\Encryption
 */
class EncryptionKeyMemoizationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Preload para a atribuicao de cobertura do pcov (ver CLAUDE.md).
		class_exists( '\FreeFormCertificate\Core\Encryption' );

		Functions\when( 'get_option' )->justReturn( array() );

		self::clear_memo();
	}

	protected function tearDown(): void {
		// Deixa o processo como foi encontrado: o memo e estatico e sobrevive
		// ao metodo, entao um teste posterior herdaria o estado deste.
		self::clear_memo();

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Zera as duas propriedades memoizadas sem tocar na API de producao.
	 */
	private static function clear_memo(): void {
		foreach ( array( 'wp_derived_enc_key', 'wp_derived_mac_key' ) as $name ) {
			$property = new \ReflectionProperty( Encryption::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * Le uma das chaves derivadas, medindo o tempo.
	 *
	 * @param string $method Nome do metodo privado.
	 * @return array{0: string, 1: float} Valor e duracao em segundos.
	 */
	private static function time_call( string $method ): array {
		$reflection = new \ReflectionMethod( Encryption::class, $method );
		$reflection->setAccessible( true );

		$started = microtime( true );
		$value   = (string) $reflection->invoke( null );

		return array( $value, microtime( true ) - $started );
	}

	/**
	 * @dataProvider derived_key_methods
	 *
	 * @param string $method Metodo derivador sob teste.
	 */
	public function test_derived_key_is_computed_once_and_reused( string $method ): void {
		list( $first, $cold ) = self::time_call( $method );
		list( $second, $warm ) = self::time_call( $method );

		// O valor nao muda -- e a propriedade que torna a memoizacao correta.
		$this->assertSame( $first, $second, 'A chave memoizada divergiu da recem-derivada.' );
		$this->assertSame( 32, strlen( $first ), 'A derivacao deve devolver 32 bytes crus.' );

		// E a segunda leitura nao refaz o trabalho.
		$this->assertGreaterThan(
			20 * $warm,
			$cold,
			sprintf(
				'A segunda chamada a %s() custou %.4f ms contra %.4f ms da primeira: o PBKDF2 esta sendo refeito.',
				$method,
				$warm * 1000,
				$cold * 1000
			)
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function derived_key_methods(): array {
		return array(
			'chave de cifra' => array( 'wp_derived_encryption_key' ),
			'chave de HMAC'  => array( 'wp_derived_hmac_key' ),
		);
	}

	/**
	 * As duas chaves sao DIFERENTES entre si -- cada uma tem o seu salt de
	 * PBKDF2, e e isso que as torna criptograficamente independentes. Uma
	 * memoizacao que compartilhasse a propriedade por engano passaria em
	 * tudo acima e quebraria aqui.
	 */
	public function test_the_two_memoized_keys_stay_independent(): void {
		list( $enc ) = self::time_call( 'wp_derived_encryption_key' );
		list( $mac ) = self::time_call( 'wp_derived_hmac_key' );

		$this->assertNotSame( $enc, $mac, 'As chaves de cifra e de HMAC colidiram: os salts nao estao separados.' );
	}

	/**
	 * A memoizacao nao pode mudar o que um ciphertext significa: um valor
	 * cifrado antes de o memo esquentar continua legivel depois, e vice-versa.
	 *
	 * E o que separa "nao recalcular" de "calcular outra coisa".
	 */
	public function test_ciphertext_survives_a_cold_and_a_warm_memo(): void {
		$plain = 'cpf-12345678901';

		// Cifra com o memo frio.
		self::clear_memo();
		$encrypted = Encryption::encrypt( $plain );
		$this->assertNotNull( $encrypted, 'A cifragem falhou com o memo frio.' );

		// Decifra com o memo quente (a cifragem acabou de preenche-lo).
		$this->assertSame( $plain, Encryption::decrypt( (string) $encrypted ) );

		// E decifra o MESMO ciphertext com o memo frio de novo.
		self::clear_memo();
		$this->assertSame(
			$plain,
			Encryption::decrypt( (string) $encrypted ),
			'Um ciphertext gravado antes do memo deixou de ser legivel depois de limpa-lo.'
		);
	}
}
