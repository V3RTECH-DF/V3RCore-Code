<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Contrato OPCIONAL — implementado só pelo canal que sabe entregar anexo
 * (RIT360 Flow, issue #23). `ChannelInterface::send()` não ganhou parâmetro
 * novo nem método novo (mudaria o contrato para os sete consumidores em
 * produção); em vez disso, `Message` carrega o anexo, e o canal que recebe
 * uma mensagem com anexo e NÃO implementa esta interface (ou implementa e
 * devolve `false`) recusa explicitamente o envio — nunca manda a mensagem
 * calada, sem o arquivo prometido.
 */
interface AttachmentCapableInterface {

	public function supportsAttachments(): bool;
}
