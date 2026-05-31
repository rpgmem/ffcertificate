<?php
/**
 * Reregistration Field Options
 *
 * Data provider for reregistration form field options:
 * - Gender, marital status, union, work schedule, position accumulation
 * - Division → Department mapping (DRE São Miguel MP org structure)
 * - Brazilian state abbreviations (UF)
 * - Default working hours template
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.8  Extracted from ReregistrationFrontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Field Options.
 */
class ReregistrationFieldOptions {

	/**
	 * Hardcoded Divisão → Setor default (DRE São Miguel MP org structure).
	 *
	 * The shipped default used to seed a new audience's `divisao_setor`
	 * field_options['groups']. After seeding, each audience owns its own
	 * map — edited per-audience in the Custom Fields editor and propagated
	 * to descendants via "Replicate to children". Edit here only to change
	 * what fresh audiences start with.
	 *
	 * @return array<string, array<string>>
	 */
	public static function get_default_divisao_setor_map(): array {
		return array(
			'DRE - Gabinete'           => array( 'Assessoria', 'Diretor Regional' ),
			'DRE - DIAF'               => array(
				'Adiantamento',
				'Alimentação',
				'Almoxarifado',
				'Apoio',
				'Assessoria',
				'Bens',
				'Compras / Aquisições',
				'Concessionárias',
				'Contabilidade',
				'Contratos',
				'Demanda',
				'Diretor(a)',
				'Expediente',
				'Gestão Documental',
				'Jurídico',
				'NTIC',
				'Parcerias',
				'Prédios',
				'Protocolo',
				'PTRF',
				'TEG',
				'Terceirizadas',
			),
			'DRE - DIAFRH'             => array(
				'Adicional',
				'Aposentadoria',
				'Atribuição',
				'Averbação',
				'CAAC',
				'Cadastro',
				'Certidão de Tempo',
				'Diretor(a)',
				'Evolução Funcional',
				'Pagamento',
				'Posse',
				'Probatório',
				'Readaptação',
				'Rede Somos',
				'Vida Funcional',
			),
			'DRE - DICEU'              => array( 'Assessoria', 'DICEU', 'Diretor(a)' ),
			'DRE - DIPED'              => array( 'Assessoria', 'CEFAI', 'DIPED', 'Diretor(a)', 'Estágios', 'NAAPA' ),
			'DRE - Supervisão'         => array( 'Assessoria', 'Diretor(a)', 'Supervisão' ),
			'ESCOLA - Gestão'          => array( 'Assistente de Direção', 'Direção' ),
			'ESCOLA - Pedagógico'      => array( 'Coordenação Pedagógica', 'Professor(a)' ),
			'ESCOLA - Quadro de Apoio' => array( 'ATE' ),
		);
	}

	/**
	 * Default "Termo de Ciência" (acknowledgment) HTML.
	 *
	 * Shipped default used to seed a new audience's `acknowledgment` standard
	 * field (stored under `field_options['html']`). After seeding, each
	 * audience owns its own copy — edited per-audience in the Custom Fields
	 * editor and propagated to descendants via "Replicate to children". It is
	 * also the render-time fallback for audiences that predate the field.
	 *
	 * Authored in pt-BR (the deployment locale) so the form and the ficha PDF
	 * share a single source of truth for the notice.
	 *
	 * @return string Rich-text HTML (wp_kses_post-safe).
	 */
	public static function get_default_termo_ciencia_html(): string {
		return '<p>Eu, em exercício na Diretoria Regional de Educação de São Miguel – DRE-MP, declaro estar ciente das orientações para o ano corrente:</p>'
			. '<ol>'
			. '<li><strong>Declaração de Família WEB:</strong> a Declaração de Família Web deverá ser feita dentro do mês de aniversário do servidor, por meio do site: <a href="https://www.declaracaofamilia.iprem.prefeitura.sp.gov.br/Login" target="_blank" rel="noopener noreferrer">https://www.declaracaofamilia.iprem.prefeitura.sp.gov.br/Login</a>. Após, deverá ser impressa e entregue no Setor de Vida Funcional, para arquivo em prontuário;</li>'
			. '<li><strong>Recadastramento Auxílio Transporte:</strong> Permanecem as mesmas orientações para aqueles que fazem jus ao recebimento do auxílio, lembrando que o recadastramento deverá ser efetuado no mês do aniversário, e o servidor deverá providenciar o recadastramento do Auxílio Transporte ANTES de efetuar o recadastramento anual (prova de vida);</li>'
			. '<li><strong>Recadastramento Anual (Prova de Vida):</strong> Permanecem as mesmas orientações, lembrando que o RG com data de expedição acima de 10 anos não será aceito, e o servidor deverá providenciar novo documento antes de efetuar o recadastramento;</li>'
			. '<li><strong>Declaração de Bens (SISPATRI):</strong> Permanecem as mesmas orientações, lembrando que deverá ser feita após o encerramento do prazo da Receita Federal, do dia 1 ao 30 do mês de Junho, por meio do site: <a href="https://controladoriageralbens.prefeitura.sp.gov.br/PaginasPublicas/login.aspx" target="_blank" rel="noopener noreferrer">https://controladoriageralbens.prefeitura.sp.gov.br/PaginasPublicas/login.aspx</a>;</li>'
			. '<li><strong>Antecipação de 13º Salário:</strong> A solicitação poderá ser preenchida e entregue à Unidade de RH a partir do 1º dia útil do exercício a que se refere a antecipação, independente do mês de aniversário do servidor.</li>'
			. '<li><strong>Entrega de Atestados Médicos/Odontológicos com pedido de Afastamento a partir de 1 (um) dia:</strong> Reiteramos que, qualquer pedido de afastamento para tratamento de saúde (pessoal ou de familiar) deverá ser informado imediatamente à chefia, mediante apresentação do atestado médico/odontológico. Em seguida, a documentação deverá ser entregue ao Setor de Vida Funcional EM MÃOS ou digitalizada para o e-mail: <a href="mailto:rhvidafuncionaldremp@sme.prefeitura.sp.gov.br">rhvidafuncionaldremp@sme.prefeitura.sp.gov.br</a>. Importante: O setor de Vida Funcional e a Chefia não se responsabilizam pelos atestados deixados no livro de ponto ou na pasta destinada exclusivamente às Declarações de Horário, bem como os que forem entregues fora do prazo legal para agendamento de perícia, se for o caso.</li>'
			. '</ol>';
	}

	/**
	 * Sexo options.
	 *
	 * @return array<string>
	 */
	public static function get_sexo_options(): array {
		return array(
			__( 'Female', 'ffcertificate' ),
			__( 'Male', 'ffcertificate' ),
			__( 'Prefer not to say', 'ffcertificate' ),
		);
	}

	/**
	 * Estado civil options.
	 *
	 * @return array<string>
	 */
	public static function get_estado_civil_options(): array {
		return array(
			__( 'Married', 'ffcertificate' ),
			__( 'Divorced', 'ffcertificate' ),
			__( 'Legally separated', 'ffcertificate' ),
			__( 'Single', 'ffcertificate' ),
			__( 'Domestic partnership', 'ffcertificate' ),
			__( 'Widowed', 'ffcertificate' ),
		);
	}

	/**
	 * Sindicato options.
	 *
	 * @return array<string>
	 */
	public static function get_sindicato_options(): array {
		return array(
			__( 'NO UNION', 'ffcertificate' ),
			'APROFEM',
			'SINPEEM',
			'SINESP',
			'SINDISEP',
			__( 'OTHER', 'ffcertificate' ),
		);
	}

	/**
	 * Jornada options.
	 *
	 * @return array<string>
	 */
	public static function get_jornada_options(): array {
		return array(
			'JB.30',
			'JBD.30',
			'JEIF.40',
			'JB.20',
		);
	}

	/**
	 * Acúmulo de cargos options.
	 *
	 * @return array<string>
	 */
	public static function get_acumulo_options(): array {
		return array(
			__( 'I do not hold', 'ffcertificate' ),
			__( 'Pension (Payslip Attached)', 'ffcertificate' ),
			__( 'I hold', 'ffcertificate' ),
		);
	}

	/**
	 * UF (state) options.
	 *
	 * @return array<string>
	 */
	public static function get_uf_options(): array {
		return array(
			'AC',
			'AL',
			'AP',
			'AM',
			'BA',
			'CE',
			'DF',
			'ES',
			'GO',
			'MA',
			'MT',
			'MS',
			'MG',
			'PA',
			'PB',
			'PR',
			'PE',
			'PI',
			'RJ',
			'RN',
			'RS',
			'RO',
			'RR',
			'SC',
			'SP',
			'SE',
			'TO',
		);
	}

	/**
	 * Default working hours data (Mon–Fri).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_default_working_hours(): array {
		return array(
			array(
				'day'    => 1,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 2,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 3,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 4,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 5,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
		);
	}
}
