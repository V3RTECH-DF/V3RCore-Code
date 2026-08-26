<?php
/**
 * Stub mínimo de \WP_Error — só o suficiente para Rest\LicenseController
 * traduzir ApiException (code/message/data.status) em teste, sem WordPress
 * carregado.
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {

	class WP_Error {

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var array<string, mixed> */
		private $data;

		/**
		 * @param string               $code
		 * @param string               $message
		 * @param array<string, mixed> $data
		 */
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}
