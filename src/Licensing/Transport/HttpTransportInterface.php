<?php
declare(strict_types=1);

namespace V3R\Core\Licensing\Transport;

/**
 * Abstração mínima sobre wp_remote_post()/wp_remote_get(), só para permitir
 * testar o HttpApiClient sem depender do WordPress carregado nem de rede
 * real. A implementação de produção (WordPressHttpTransport) chama as
 * funções wp_remote_* de verdade; testes injetam uma implementação falsa.
 *
 * Não decide nada sobre o protocolo (assinatura, cache, erro de negócio) —
 * só transporta bytes HTTP e devolve um resultado normalizado.
 */
interface HttpTransportInterface {

	/**
	 * @param string               $url     URL completa do endpoint.
	 * @param array<string, mixed> $body    Corpo, enviado como JSON.
	 * @param int                  $timeout Timeout em segundos.
	 */
	public function post( string $url, array $body, int $timeout ): HttpTransportResult;

	/**
	 * @param string $url     URL completa, já com a query string montada.
	 * @param int    $timeout Timeout em segundos.
	 */
	public function get( string $url, int $timeout ): HttpTransportResult;
}
