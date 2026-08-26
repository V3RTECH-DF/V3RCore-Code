<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

use V3R\Core\Updater\UpdateGate;

/**
 * Tela padrão de licença, em PHP simples e estilos nativos do wp-admin —
 * sem build, sem SPA própria. Saída para o plugin hospedeiro que NÃO tem
 * interface administrativa própria (ex.: V3RProp); plugins que desenham a
 * própria aba (V3RLGPD, V3REvent) consomem os endpoints REST (§8) e nunca
 * instanciam/registram esta classe.
 *
 * Deliberadamente NÃO registrada por Bootstrap::boot() — ver
 * Bootstrap::createAdminPage(). Instanciar esta classe nunca falha nem
 * registra nada por si só; só register() liga os hooks, e só faz isso
 * quando reconhece um WordPress de verdade por baixo.
 *
 * Regra de produto que esta tela precisa comunicar corretamente
 * (docs/api-contract.md §8.11): licença expirada/revogada NUNCA desativa o
 * plugin nem degrada funcionalidade — só a atualização automática para.
 * Todo texto vem de LicenseStatePresenter::present()['status_message'],
 * nunca escrito aqui de novo.
 */
class AdminPage {

	/** @var LicenseManager */
	private $manager;

	/** @var string */
	private $capability;

	/** @var LicenseStatePresenter */
	private $presenter;

	public function __construct( LicenseManager $manager, UpdateGate $gate, string $capability ) {
		$this->manager    = $manager;
		$this->capability = $capability;
		$this->presenter  = new LicenseStatePresenter( $gate );
	}

	/**
	 * Registra os hooks do WordPress necessários para a tela existir.
	 * No-op seguro fora do WordPress (mesma defesa de Updater\UpdateChecker)
	 * — nunca quebra o carregamento do plugin hospedeiro.
	 */
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
	}

	public function registerMenu(): void {
		add_options_page(
			__( 'Licença', 'v3r-core' ),
			__( 'Licença', 'v3r-core' ),
			$this->capability,
			$this->menuSlug(),
			array( $this, 'render' )
		);
	}

	public function getManager(): LicenseManager {
		return $this->manager;
	}

	/**
	 * Decide o que fazer com uma submissão de formulário (ativar, desativar,
	 * verificar agora) e o texto de retorno — pura o bastante para ser
	 * testada sem WordPress: quem chama (render()) já validou nonce e
	 * capability antes de montar $input.
	 *
	 * @param array<string, string> $input `action` e, quando `action = activate`, `license_key`.
	 * @return array{type: string, message: string}
	 */
	public function handleAction( array $input ): array {
		$action = $input['action'] ?? '';

		try {
			switch ( $action ) {
				case 'activate':
					$licenseKey = trim( (string) ( $input['license_key'] ?? '' ) );

					if ( '' === $licenseKey ) {
						return $this->notice( 'error', 'Informe a chave de licença.' );
					}

					$this->manager->activate( $licenseKey );

					return $this->notice( 'success', 'Licença ativada com sucesso.' );

				case 'deactivate':
					$this->manager->deactivate();

					return $this->notice( 'success', 'Licença desativada neste site.' );

				case 'refresh':
					$this->manager->refresh( true );

					return $this->notice( 'success', 'Verificação concluída.' );

				default:
					return $this->notice( 'error', 'Ação desconhecida.' );
			}
		} catch ( ApiException $exception ) {
			return $this->notice(
				'error',
				'Não foi possível concluir agora: ' . $exception->getMessage() . '. O plugin continua funcionando normalmente.'
			);
		}
	}

	/**
	 * Renderiza a tela: processa a submissão (se houver, com nonce e
	 * capability já checados aqui) e desenha o estado corrente. Só chamado
	 * pelo próprio WordPress (callback de add_options_page()) — depende de
	 * $_POST, nonce e das funções de escape do wp-admin.
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'v3r-core' ) );
		}

		$notice = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado explicitamente logo abaixo via check_admin_referer().
		if ( isset( $_POST['v3r_core_license_action'] ) ) {
			check_admin_referer( $this->nonceAction() );

			$notice = $this->handleAction(
				array(
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					'action'      => sanitize_text_field( wp_unslash( $_POST['v3r_core_license_action'] ) ),
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					'license_key' => isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '',
				)
			);
		}

		$state = $this->presenter->present( $this->manager->getState() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Licença', 'v3r-core' ) . '</h1>';

		if ( null !== $notice ) {
			$noticeClass = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $noticeClass ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		echo '<p>' . esc_html( (string) $state['status_message'] ) . '</p>';

		if ( LicenseStatus::INACTIVE === $state['status'] ) {
			$this->renderActivateForm();
		} else {
			$this->renderStateTable( $state );
			$this->renderActionButtons();
		}

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $state Schema de LicenseStatePresenter::present().
	 */
	private function renderStateTable( array $state ): void {
		echo '<table class="widefat striped" style="max-width:600px">';
		$this->renderRow( __( 'Chave', 'v3r-core' ), (string) $state['license_key_masked'] );
		$this->renderRow( __( 'Status', 'v3r-core' ), (string) $state['status'] );
		$this->renderRow( __( 'Expira em', 'v3r-core' ), null === $state['expires_at'] ? __( 'Sem expiração', 'v3r-core' ) : (string) $state['expires_at'] );
		$this->renderRow( __( 'Ativações', 'v3r-core' ), $state['activations_used'] . ' / ' . ( null === $state['activations_max'] ? '∞' : (string) $state['activations_max'] ) );
		$this->renderRow( __( 'Última verificação', 'v3r-core' ), null === $state['last_checked_at'] ? __( 'Nunca', 'v3r-core' ) : (string) $state['last_checked_at'] );
		$this->renderRow( __( 'Recebe atualizações', 'v3r-core' ), $state['receives_updates'] ? __( 'Sim', 'v3r-core' ) : __( 'Não', 'v3r-core' ) );
		echo '</table>';
	}

	private function renderRow( string $label, string $value ): void {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function renderActivateForm(): void {
		echo '<form method="post">';
		wp_nonce_field( $this->nonceAction() );
		echo '<input type="hidden" name="v3r_core_license_action" value="activate" />';
		echo '<p><label for="v3r_core_license_key">' . esc_html__( 'Chave de licença', 'v3r-core' ) . '</label><br />';
		echo '<input type="text" id="v3r_core_license_key" name="license_key" class="regular-text" required /></p>';
		submit_button( __( 'Ativar', 'v3r-core' ) );
		echo '</form>';
	}

	private function renderActionButtons(): void {
		echo '<form method="post" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( $this->nonceAction() );
		echo '<input type="hidden" name="v3r_core_license_action" value="refresh" />';
		submit_button( __( 'Verificar agora', 'v3r-core' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" style="display:inline-block">';
		wp_nonce_field( $this->nonceAction() );
		echo '<input type="hidden" name="v3r_core_license_action" value="deactivate" />';
		submit_button( __( 'Desativar neste site', 'v3r-core' ), 'delete', 'submit', false );
		echo '</form>';
	}

	private function menuSlug(): string {
		return 'v3r-core-license-' . $this->manager->getProductSlug();
	}

	private function nonceAction(): string {
		return 'v3r_core_license_' . $this->manager->getProductSlug();
	}

	/**
	 * @return array{type: string, message: string}
	 */
	private function notice( string $type, string $message ): array {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
