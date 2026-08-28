<?php
/**
 * Stub mínimo de \WP_User — só o suficiente para o 4º argumento do filtro
 * `user_has_cap` (Licensing\CapabilityGate::grant()) existir em teste, sem
 * WordPress carregado.
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_User' ) ) {

	class WP_User {

		/** @var int */
		public $ID = 0;
	}
}
