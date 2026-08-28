<?php
/**
 * Stub de `user_can()` fiel o bastante ao WordPress real para reproduzir a
 * recursão de V3RCore-Code#12: no WordPress de verdade, `user_can($uid, $cap)`
 * termina em `WP_User::has_cap()`, que dispara
 * `apply_filters('user_has_cap', $allcaps, $caps, $args, $user)` — o mesmo
 * filtro em que `Licensing\CapabilityGate::grant()` está pendurado. Sem stub
 * nenhuma reentrância aconteceria nos testes, e o teste de não-recursão não
 * provaria nada.
 *
 * `add_filter()`/`apply_filters()` já existem em PucFunctionStubs.php
 * (sempre carregado por tests/bootstrap.php) — este arquivo só acrescenta o
 * elo que falta.
 *
 * Disjuntor de segurança: sem a guarda de saída antecipada, a recursão real
 * é infinita (é o próprio defeito) e travaria/estouraria memória do processo
 * de teste. Em vez de deixar isso acontecer, o stub conta as chamadas e
 * lança RuntimeException ao passar de um teto — suficiente para o teste
 * falhar de forma clara quando alguém remover a guarda, sem travar a suíte.
 */

declare(strict_types=1);

if ( ! function_exists( 'user_can' ) ) {

	/**
	 * @param int $userId
	 * @throws RuntimeException Disjuntor de segurança — ver docblock do arquivo.
	 */
	function user_can( $userId, string $capability ): bool {
		$GLOBALS['v3r_core_test_user_can_calls'] = ( $GLOBALS['v3r_core_test_user_can_calls'] ?? 0 ) + 1;

		if ( $GLOBALS['v3r_core_test_user_can_calls'] > 200 ) {
			throw new RuntimeException(
				'user_can() reentrou em user_has_cap mais de 200 vezes — recursão sem guarda de saída antecipada (V3RCore-Code#12).'
			);
		}

		$granted = $GLOBALS['v3r_core_test_user_can_base_caps'][ $userId ] ?? array();

		$allcaps = apply_filters(
			'user_has_cap',
			array_fill_keys( $granted, true ),
			array( $capability ),
			array( $capability, $userId ),
			null
		);

		return ! empty( $allcaps[ $capability ] );
	}
}
