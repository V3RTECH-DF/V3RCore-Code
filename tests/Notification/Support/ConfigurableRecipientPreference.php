<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification\Support;

use V3R\Core\Notification\RecipientPreferenceInterface;

/**
 * Double controlável: por padrão permite tudo (mayReceive() == true);
 * `denyFor()` marca um par recipient+category específico como recusado —
 * é o controle negativo que prova que a checagem de preferência distingue
 * quem optou por não receber de quem nunca foi perguntado.
 */
final class ConfigurableRecipientPreference implements RecipientPreferenceInterface {

	/** @var array<string, bool> */
	private array $denied = array();

	public function denyFor( string $recipient, string $category ): void {
		$this->denied[ $recipient . '|' . $category ] = true;
	}

	public function mayReceive( string $recipient, string $category ): bool {
		return ! isset( $this->denied[ $recipient . '|' . $category ] );
	}
}
