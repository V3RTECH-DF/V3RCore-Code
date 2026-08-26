<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Tela de ativação de licença (menu do plugin consumidor no wp-admin).
 *
 * TODO(fatia-2): registrar a página via admin_menu, o formulário de
 * ativação/desativação e o feedback de estado (válida/expirada/em graça).
 * Decide o que o usuário vê; a lógica de ativação em si é do LicenseManager.
 */
class AdminPage {

	/** @var LicenseManager */
	private $manager;

	public function __construct( LicenseManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Registra os hooks do WordPress necessários para a tela existir.
	 */
	public function register(): void {
		// Intencionalmente vazio nesta fatia — nada de rede nem de admin_menu
		// real ainda. Instanciar esta classe não pode lançar nem quebrar o
		// carregamento do plugin (ver Bootstrap).
	}

	public function getManager(): LicenseManager {
		return $this->manager;
	}
}
