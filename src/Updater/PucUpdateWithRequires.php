<?php
declare(strict_types=1);

namespace V3R\Core\Updater;

use YahnisElsts\PluginUpdateChecker\v5p7\Plugin\Update as PucPluginUpdate;

/**
 * Corrige V3RCore-Code#8: `Plugin\Update::getFieldNames()` (upstream, ver
 * `Puc\v5p7\Update` e `Puc\v5p7\Plugin\Update`) não inclui `requires` nem
 * na lista base nem em `$extraFields`, e `toWpFormat()` só copia os campos
 * que ele conhece pelo nome. Resultado: mesmo com `PluginInfo->requires`
 * corretamente preenchido por PucBridge::requestInfo(), o valor morre na
 * conversão para `Update` e nunca chega ao transiente `update_plugins` do
 * WordPress.
 *
 * Esta classe só acrescenta o campo que falta, sem alterar nada do
 * comportamento herdado. É instanciada por PucBridge através do filtro
 * `pre_inject_update`, o ponto que o próprio PUC expõe para "let plugins
 * filter the update info before it's passed on to WordPress" — chamado
 * logo antes de `toWpFormat()`, então é o único lugar onde dá para agir
 * sem editar `vendor-prefixed/` nem fazer fork do upstream.
 */
final class PucUpdateWithRequires extends PucPluginUpdate {

	/** @var string|null */
	public $requires;

	/**
	 * Cópia de $update com `requires` acrescentado. Não reaproveita
	 * `Update::fromObject()`/`fromPluginInfo()` porque ambos usam `new
	 * self()` vinculado à classe onde estão escritos no upstream
	 * (`Plugin\Update`), nunca a esta subclasse — reimplementar aqui é o
	 * que garante que a cópia continua sendo um PucUpdateWithRequires.
	 *
	 * @param PucPluginUpdate $update   Update já produzido pelo fluxo padrão do PUC.
	 * @param string|null     $requires Valor resolvido por UpdateAvailability::getRequires().
	 */
	public static function fromExisting( PucPluginUpdate $update, ?string $requires ): self {
		$copy = new self();
		$copy->copyFields( $update, $copy );
		$copy->requires = $requires;

		return $copy;
	}

	public function toWpFormat() {
		/** @var \stdClass $wpUpdate stdClass aceita propriedade dinâmica sem aviso do PHPStan; o upstream devolve `object` sem tipar mais que isso. */
		$wpUpdate = parent::toWpFormat();

		if ( null !== $this->requires && '' !== $this->requires ) {
			$wpUpdate->requires = $this->requires;
		}

		return $wpUpdate;
	}
}
