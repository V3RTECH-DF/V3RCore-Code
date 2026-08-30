<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Mapa canal → implementação. Existe para que acrescentar um canal seja
 * registrar mais uma entrada aqui — NotificationDispatcher e quem pede o
 * envio (Message::getChannel()) nunca mudam quando um canal novo entra.
 */
final class ChannelRegistry {

	/** @var array<string, ChannelInterface> */
	private $channels = array();

	/**
	 * @param ChannelInterface[] $channels
	 */
	public function __construct( array $channels = array() ) {
		foreach ( $channels as $channel ) {
			$this->register( $channel );
		}
	}

	public function register( ChannelInterface $channel ): void {
		$this->channels[ $channel->name() ] = $channel;
	}

	public function has( string $name ): bool {
		return isset( $this->channels[ $name ] );
	}

	public function get( string $name ): ChannelInterface {
		if ( ! $this->has( $name ) ) {
			throw new \InvalidArgumentException( "Canal não registrado: {$name}" );
		}

		return $this->channels[ $name ];
	}
}
