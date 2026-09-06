<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Metadado de um grupo de telas — só rótulo e ordem (docs/navegacao-do-painel.md
 * §2). O grupo **não** tem permissão própria: visibilidade dele é sempre
 * derivada das telas que sobram dentro dele, nunca decidida aqui (ver
 * TreeBuilder).
 *
 * Declarar o `Group` é opcional. Uma `Screen` pode referenciar uma chave de
 * grupo sem que ninguém tenha chamado `Registry::addGroup()` para ela — a
 * árvore ainda funciona (rótulo cai para a própria chave), só sem o rótulo
 * bonito nem uma ordem explícita.
 */
final class Group {

	/** @var string */
	private $key;

	/** @var string */
	private $label;

	/** @var int */
	private $order;

	/**
	 * @throws \InvalidArgumentException `key` ou `label` vazios.
	 */
	public function __construct( string $key, string $label, int $order ) {
		if ( '' === trim( $key ) ) {
			throw new \InvalidArgumentException( 'Group::key não pode ser vazio.' );
		}

		if ( '' === trim( $label ) ) {
			throw new \InvalidArgumentException( 'Group::label não pode ser vazio.' );
		}

		$this->key   = $key;
		$this->label = $label;
		$this->order = $order;
	}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return $this->label;
	}

	public function order(): int {
		return $this->order;
	}
}
