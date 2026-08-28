<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\CapabilityGate;

/**
 * Prende a recursão de V3RCore-Code#12 na própria biblioteca — antes desta
 * issue, o teste equivalente só existia duplicado em dois plugins
 * consumidores (V3RLGPD-Code e V3REvent-Code, `LicenseCapsNoRecursionTest`),
 * mesmo formato aqui: conta CHAMADAS, não confere retorno. Recursão
 * infinita não produz resposta errada — produz ausência de resposta (ou,
 * neste ambiente de teste, estouro de memória do processo). Um teste que só
 * olhasse `$allcaps` passaria com o código defeituoso até o processo
 * morrer.
 *
 * O `user_can()` de `tests/Support/CapabilityFunctionStubs.php` reentra de
 * verdade em `user_has_cap` (via `apply_filters`), reproduzindo o ciclo:
 * `user_can() → apply_filters('user_has_cap') → CapabilityGate::grant() →
 * $decider → user_can() → …`. A guarda de `grant()` — devolver `$allcaps`
 * sem chamar `$decider` quando nenhuma capability pedida é de licença — é o
 * que quebra o ciclo.
 */
final class CapabilityGateNoRecursionTest extends TestCase {

	private const UID = 4242;

	protected function setUp(): void {
		// PucFunctionStubs.php acumula filtros num global só, sem remoção
		// entre testes — sem isto, o filtro de um teste anterior
		// continuaria pendurado em user_has_cap no próximo.
		$GLOBALS['v3r_core_test_puc_filters']        = array();
		$GLOBALS['v3r_core_test_user_can_calls']     = 0;
		$GLOBALS['v3r_core_test_user_can_base_caps'] = array( self::UID => array( 'manage_options' ) );
	}

	/**
	 * A função de decisão que um plugin real forneceria: consulta o
	 * próprio RBAC, que por sua vez consulta `user_can()` — exatamente o
	 * padrão que causou V3RLGPD-Code#74.
	 *
	 * @return callable
	 */
	private function deciderReentrandoNoRbac(): callable {
		return static function ( int $userId, string $capability ): bool {
			// Simula Permissions::user_can()/Auth::can() dos dois
			// plugins de referência: pergunta ao core por
			// 'manage_options' como parte da decisão.
			return user_can( $userId, 'manage_options' );
		};
	}

	private function gate(): CapabilityGate {
		$gate = new CapabilityGate( 'v3rlgpd_settings_view', 'v3rlgpd_settings_manage', $this->deciderReentrandoNoRbac() );
		$gate->register();

		return $gate;
	}

	/**
	 * O caso que causa o estouro: o WordPress avalia uma capability
	 * qualquer (aqui 'manage_options', como no wp-admin real e dentro do
	 * próprio decider) e o filtro é chamado.
	 */
	public function test_capability_alheia_nao_faz_a_funcao_de_decisao_consultar_user_can(): void {
		$this->gate();

		$out = user_can( self::UID, 'manage_options' );

		self::assertTrue( $out, 'manage_options está entre as capabilities base do usuário de teste.' );
		self::assertSame(
			1,
			$GLOBALS['v3r_core_test_user_can_calls'],
			'user_can() só deveria ter sido chamado uma vez (a chamada de fora) — qualquer chamada ' .
			'a mais significa que o filtro consultou a função de decisão para uma capability que não é de licença.'
		);
	}

	/** Mesmo cenário para outras capabilities do core, que rodam em toda tela do wp-admin. */
	public function test_capabilities_do_core_nao_disparam_a_funcao_de_decisao(): void {
		$this->gate();

		foreach ( array( 'activate_plugins', 'resume_plugins', 'edit_posts', 'switch_themes' ) as $cap ) {
			$GLOBALS['v3r_core_test_user_can_base_caps'][ self::UID ][] = $cap;
			user_can( self::UID, $cap );
		}

		self::assertSame(
			4,
			$GLOBALS['v3r_core_test_user_can_calls'],
			'Uma chamada de fora por capability, e nenhuma chamada extra vinda da função de decisão.'
		);
	}

	/**
	 * Controle positivo: pedida a capability de licença, a guarda TEM que
	 * deixar passar e a função de decisão TEM que ser consultada — sem
	 * isto, uma guarda que bloqueasse tudo passaria nos testes acima.
	 */
	public function test_capability_de_licenca_chega_na_funcao_de_decisao_e_respeita_a_resposta(): void {
		$this->gate();

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- capability sintética de licença (V3RCore-Code#12), não do core; o stub user_can() é de teste, não a função real do WordPress.
		$out = user_can( self::UID, 'v3rlgpd_settings_manage' );

		self::assertTrue( $out, 'O decider concede quando user_can(uid, manage_options) é true.' );
		self::assertGreaterThan(
			1,
			$GLOBALS['v3r_core_test_user_can_calls'],
			'Pedida a capability de licença, a função de decisão precisa ter sido chamada — ela ' .
			'mesma reentra em user_can(), então o total tem que passar de 1 (a chamada de fora).'
		);
	}

	/** A guarda respeita "não" tanto quanto "sim" — não é um passa-tudo disfarçado. */
	public function test_capability_de_licenca_nao_concedida_respeita_a_negativa(): void {
		$gate = new CapabilityGate(
			'v3rlgpd_settings_view',
			'v3rlgpd_settings_manage',
			static function ( int $userId, string $capability ): bool {
				return false;
			}
		);
		$gate->register();

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- capability sintética de licença (V3RCore-Code#12), não do core; o stub user_can() é de teste, não a função real do WordPress.
		$out = user_can( self::UID, 'v3rlgpd_settings_manage' );

		self::assertFalse( $out, 'A função de decisão respondeu não; a guarda não pode conceder mesmo assim.' );
	}

	/** A capability de leitura, pedida sozinha, não concede a de gestão. */
	public function test_capability_de_leitura_nao_concede_a_de_gestao(): void {
		$grantedCapability = null;

		$gate = new CapabilityGate(
			'v3rlgpd_settings_view',
			'v3rlgpd_settings_manage',
			static function ( int $userId, string $capability ) use ( &$grantedCapability ): bool {
				$grantedCapability = $capability;

				return true;
			}
		);
		$gate->register();

		$allcaps = $gate->grant( array(), array( 'v3rlgpd_settings_view' ), array( 'v3rlgpd_settings_view', self::UID ), null );

		self::assertSame( 'v3rlgpd_settings_view', $grantedCapability );
		self::assertTrue( $allcaps['v3rlgpd_settings_view'] ?? false );
		self::assertArrayNotHasKey( 'v3rlgpd_settings_manage', $allcaps );
	}
}
