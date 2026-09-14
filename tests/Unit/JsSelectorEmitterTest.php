<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\JsIdSelectors;
use PHPUnit\Framework\TestCase;

/**
 * Um id que o JS procura tem de ser emitido por alguém (#1220).
 *
 * Três vezes numa sessão um teste passou verde sobre marcação que o produto
 * nunca renderizou: `.ffc-tab.active` depois da renomeação do #1170, que
 * deixou o painel do usuário sem carregar painel nenhum (#1204); marcação de
 * uma tela montada à mão na tela de outra (#1184); e `#ffc_rereg_acumulo`,
 * que nenhum PHP emite e que existia só no JS que o procurava e numa fixture
 * que o inventava (#1219). O padrão é sempre o mesmo: **uma fixture escrita à
 * mão envelhece em silêncio**.
 *
 * Esta guarda vê a metade do PRODUTO -- o seletor que ninguém emite. A outra
 * metade (a fixture corresponder à marcação real) exigiria comparar o HTML do
 * teste com o que o PHP emite através de templates com condicionais, e não
 * está aqui. Vale estar escrito, porque nos três casos o defeito estava nas
 * duas metades.
 *
 * **A forma ingênua não funciona, e a medição diz por quê.** Varrer só PHP
 * reportaria dezenas de falsos: a maior parte do DOM que o painel procura é
 * criada pelo próprio JS. A varredura cobre PHP e JS, e as formas que ela
 * precisou aprender estão no docblock de `JsIdSelectors` -- cada uma entrou
 * por causa de um falso positivo que ela produziu.
 *
 * @covers \FreeFormCertificate\Tests\Support\JsIdSelectors
 */
class JsSelectorEmitterTest extends TestCase {

	/**
	 * Ids que o WordPress emite, não nós.
	 *
	 * Exceção do mesmo tipo que as `VENDOR_CLASSES` do `ClassNamingIdiomTest`:
	 * a marcação existe, só não no nosso repositório.
	 *
	 * @var array<string, string>
	 */
	private const CORE_IDS = array(
		'wpbody-content' => 'Contêiner de conteúdo do wp-admin, emitido por `wp-admin/admin-header.php`.',
		'title'          => 'Campo de título do editor de post, emitido por `wp-admin/edit-form-advanced.php`.',
	);

	/**
	 * Ids que o JS procura e que ninguém emite.
	 *
	 * **Ratchet de mão dupla**: um id novo sem emissor falha (escreva a
	 * marcação, ou apague a busca); um id daqui que ganhou emissor também
	 * falha (tire-o da lista para travar o ganho).
	 *
	 * Nenhum destes é defeito VIVO -- todos ficam atrás de uma checagem de
	 * comprimento e falham em silêncio, que é exatamente por que ninguém
	 * notou. São código morto à espera de decisão, e a guarda aponta; quem
	 * decide é uma pessoa.
	 *
	 * @var array<string, string>
	 */
	private const WITHOUT_EMITTER = array(
		// O select dependente tem DUAS implementações. A viva é por classe
		// (`field-dependent-select.php` emite `.ffc-dependent-select` /
		// `.ffc-dep-parent` / `.ffc-dep-child`, e o handler está em
		// `ffc-reregistration-frontend.js`); esta por id é a anterior,
		// superada e deixada para trás. Tem `if ( ! $mapEl.length ) return;`.
		'ffc-divisao-setor-map' => 'Legado do select dependente, superado pela implementação por classe.',
		'ffc_rereg_divisao'     => 'Idem -- o pai do select dependente na versão por id.',
		'ffc_rereg_setor'       => 'Idem -- o filho do select dependente na versão por id.',

		// O "Migration Manager Dropdown Controller v2.1.0" dirige um botão e
		// um menu que NÃO EXISTEM: de `ffc-migrations` só há
		// `.ffc-migrations-settings-wrap`, o invólucro da aba. O controlador
		// retorna cedo, mas ANTES disso injeta `#ffc-migrations-overlay` no
		// body de toda tela do wp-admin -- inofensivo, e real.
		'ffc-migrations-btn'    => 'Gatilho de um dropdown de migrações cuja marcação não existe.',
		'ffc-migrations-menu'   => 'O menu desse mesmo dropdown; nem por id nem por classe é emitido.',

		// O seletor de imagem de fundo do PDF. O campo de URL tem inclusive
		// um fallback documentado (`input[name*="bg_image"]`), o que sugere
		// que o id já era incerto quando foi escrito.
		'ffc_bg_image_url'      => 'Campo de URL da imagem de fundo; há fallback por atributo `name`.',
		'ffc_bg_image_preview'  => 'Prévia da imagem de fundo; a busca é guardada por comprimento.',
	);

	public function test_every_id_the_javascript_looks_for_is_emitted_by_someone(): void {
		$consumers = JsIdSelectors::consumers();

		// Autoverificação: uma varredura vazia jamais pode ser lida como
		// "limpa" (a lição do #1071 / #1094). Os dois lados precisam ter
		// encontrado população.
		$this->assertGreaterThan(
			100,
			count( $consumers ),
			'A varredura de consumo voltou quase vazia — o parser quebrou, e um verde aqui não significaria nada.'
		);
		$this->assertGreaterThan(
			300,
			JsIdSelectors::emitted_count(),
			'A varredura de emissão voltou quase vazia — todo id pareceria órfão, ou nenhum.'
		);

		$orphans = array();
		foreach ( $consumers as $id => $paths ) {
			if ( isset( self::CORE_IDS[ $id ] ) ) {
				continue;
			}
			if ( ! JsIdSelectors::is_emitted( $id ) ) {
				$orphans[ $id ] = $paths;
			}
		}

		$new = array_diff_key( $orphans, self::WITHOUT_EMITTER );
		$this->assertSame(
			array(),
			array_map( static fn( array $p ): string => implode( ', ', $p ), $new ),
			"Um id novo que o JavaScript procura e que NINGUÉM emite.\n"
			. "Ou a marcação não existe (o defeito do #1219), ou foi renomeada e a busca ficou para trás (o do #1204).\n"
			. 'Se a forma de emissão é nova, ensine a varredura em `JsIdSelectors` — nunca acrescente à lista para calar a guarda.'
		);

		$fixed = array_diff_key( self::WITHOUT_EMITTER, $orphans );
		$this->assertSame(
			array(),
			$fixed,
			'Estes ids ganharam emissor (ou a busca foi apagada). Tire-os de `WITHOUT_EMITTER` para travar o ganho.'
		);
	}

	public function test_the_core_exceptions_still_match_a_real_lookup(): void {
		// Uma exceção que deixou de casar com qualquer busca é lixo que
		// esconde o próximo caso — a mesma regra das exceções do
		// `DarkModeCssTest` e do `AdminPageScopeTest`.
		$consumers = JsIdSelectors::consumers();

		foreach ( self::CORE_IDS as $id => $why ) {
			$this->assertArrayHasKey(
				$id,
				$consumers,
				"`#{$id}` está na lista de ids do WordPress, mas nenhum JS o procura mais. Remova a exceção. {$why}"
			);
		}
	}

	public function test_a_selector_assembled_at_runtime_is_not_charged_an_emitter(): void {
		// `'#ffc-tabpanel-' + aba` nunca existe como nome inteiro, então
		// cobrar emissor dele reportaria um órfão que jamais existiu. A
		// mesma limitação que o `CssClassEmitters` registra para classes.
		$this->assertArrayNotHasKey( 'ffc-tabpanel-', JsIdSelectors::consumers() );
		$this->assertArrayNotHasKey( 'ffc-tabpanel', JsIdSelectors::consumers() );
	}
}
