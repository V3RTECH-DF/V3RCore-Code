#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Runner do Strauss para CI e uso local, a partir da raiz do repositório.
 *
 * Gera vendor-prefixed/ com as dependências de runtime (plugin-update-checker)
 * namespaceadas em V3R\Core\Vendor\*. É necessário porque vendor-prefixed/ é
 * gitignored: quem consome a lib roda `composer install` e depois `composer prefix`
 * (que chama este script) para que o autoload prefixado exista.
 *
 * Strauss, instalado como dev-dependency, precisa de Composer\Factory em runtime,
 * mas o pacote composer/composer omite o próprio namespace do classmap gerado.
 * Este wrapper registra o namespace Composer\ antes de rodar o Strauss — sem
 * alterar nenhuma configuração do Strauss (que fica em composer.json → extra.strauss).
 *
 * Uso: php tools/strauss.php [--quiet]   (a partir da raiz do repositório)
 */

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/vendor/autoload.php';

$composerSrc = $projectRoot . '/vendor/composer/composer/src';
if (is_dir($composerSrc)) {
    spl_autoload_register(static function (string $class) use ($composerSrc): void {
        if (strncmp($class, 'Composer\\', 9) !== 0) {
            return;
        }
        $relative = substr($class, strlen('Composer\\'));
        $file = $composerSrc . '/Composer/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }, true, true);
}

$app = new \BrianHenryIE\Strauss\Console\Application('0.19.5');
$exitCode = $app->run();

// Normaliza permissões (alguns pacotes são extraídos com dirs 0700, ilegíveis
// pelo autoload por classmap em runtime). Garante dirs 0755 e arquivos 0644.
$vendorPrefixed = $projectRoot . '/vendor-prefixed';
if (is_dir($vendorPrefixed)) {
    @chmod($vendorPrefixed, 0755);
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($vendorPrefixed, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        /** @var \SplFileInfo $item */
        @chmod($item->getPathname(), $item->isDir() ? 0755 : 0644);
    }
}

exit($exitCode);
