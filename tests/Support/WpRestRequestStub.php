<?php
/**
 * Stub mínimo de \WP_REST_Request — parâmetros e headers em memória, só o
 * suficiente para testar Rest\LicenseController sem WordPress carregado.
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Request' ) ) {

	class WP_REST_Request {

		/** @var array<string, mixed> */
		private $params = array();

		/** @var array<string, string> */
		private $headers = array();

		/**
		 * @param string $key
		 * @param mixed  $value
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		/**
		 * @return string|null
		 */
		public function get_header( string $key ) {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}
	}
}
