<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Contrato entre a biblioteca e o assinador de verdade — que assina com
 * TCPDF/FPDI ou o que quer que o plugin hospedeiro use. Só o contrato mora
 * aqui (issue #27): a biblioteca não gera PDF nem ganha dependência de
 * assinatura, para não trazer bibliotecas pesadas (TCPDF, FPDI — com
 * constantes globais que brigariam com o Strauss) para quem nem usa esta
 * peça.
 */
interface SignerInterface {

	/**
	 * Assina o arquivo com o material do certificado, e devolve o caminho
	 * do arquivo assinado (pode ser o mesmo caminho, sobrescrito, ou um
	 * novo — decisão de quem implementa).
	 *
	 * @param string              $unsignedFilePath Caminho absoluto do arquivo a assinar.
	 * @param CertificateMaterial $material         Certificado (arquivo + senha, já em texto pleno).
	 *
	 * @throws SigningException Falha declarada — nunca deixa passar uma exceção genérica
	 *                           do motor de PDF sem traduzi-la para um dos códigos do
	 *                           contrato.
	 */
	public function sign( string $unsignedFilePath, CertificateMaterial $material ): string;
}
