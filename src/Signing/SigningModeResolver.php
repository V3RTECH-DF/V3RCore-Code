<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use DateTimeImmutable;

/**
 * Decide qual modo de assinatura usar — função pura, sem tocar disco nem
 * banco (issue #27). Recebe fatos já apurados por quem chama ("há arquivo
 * de certificado", "há data de validade conhecida", "a validade é
 * futura") e devolve o modo mais o motivo, nunca um sem o outro.
 *
 * Conservadora por desenho: qualquer incerteza (sem arquivo, sem validade
 * conhecida, validade vencida) cai para o modo degradado
 * (SigningMode::REGISTRO_ELETRONICO). O único caminho para
 * SigningMode::CERTIFICADO_DIGITAL é "há arquivo E há validade conhecida E
 * ela é futura" — as três, nunca menos.
 *
 * Deliberadamente não consulta relógio nem sistema de arquivos: quem chama
 * apura "agora" e "existe arquivo" fora daqui e entrega os fatos prontos —
 * é o que torna esta decisão testável para qualquer instante, inclusive
 * datas futuras/passadas arbitrárias, sem mexer no relógio do processo.
 */
final class SigningModeResolver {

	/**
	 * @param bool                   $hasCertificateFile Há arquivo de certificado cadastrado e legível.
	 * @param DateTimeImmutable|null $expiresAt          Validade conhecida do certificado, ou null se desconhecida.
	 * @param DateTimeImmutable      $now                Instante de referência para comparar a validade.
	 */
	public static function decide(
		bool $hasCertificateFile,
		?DateTimeImmutable $expiresAt,
		DateTimeImmutable $now
	): SigningModeDecision {
		if ( ! $hasCertificateFile ) {
			return new SigningModeDecision( SigningMode::REGISTRO_ELETRONICO, SigningModeReason::SEM_CERTIFICADO );
		}

		if ( null === $expiresAt ) {
			return new SigningModeDecision( SigningMode::REGISTRO_ELETRONICO, SigningModeReason::SEM_VALIDADE_CONHECIDA );
		}

		if ( $expiresAt <= $now ) {
			return new SigningModeDecision( SigningMode::REGISTRO_ELETRONICO, SigningModeReason::CERTIFICADO_VENCIDO );
		}

		return new SigningModeDecision( SigningMode::CERTIFICADO_DIGITAL, SigningModeReason::CERTIFICADO_VALIDO );
	}

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
