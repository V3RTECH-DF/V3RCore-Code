<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Um anexo de arquivo já materializado em disco, pronto para ir com a
 * mensagem (RIT360 Flow, issue #23) — imutável, canal-agnóstico: cada canal
 * decide se sabe entregar (ver AttachmentCapableInterface). `$path` é
 * SEMPRE um caminho local já existente no momento do envio; quem monta o
 * anexo (o consumidor) é responsável por gravar e depois apagar o arquivo
 * temporário — esta classe só carrega a referência.
 */
final class Attachment {

	/** @var string */
	private $path;

	/** @var string */
	private $filename;

	public function __construct( string $path, string $filename ) {
		$this->path     = $path;
		$this->filename = $filename;
	}

	public function getPath(): string {
		return $this->path;
	}

	public function getFilename(): string {
		return $this->filename;
	}
}
