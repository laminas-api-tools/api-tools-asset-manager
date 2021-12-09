<?php

declare(strict_types=1);

namespace Laminas\ApiTools\AssetManager;

use function file_get_contents;
use function in_array;
use function is_array;
use function preg_match;
use function token_get_all;

use const T_EVAL;
use const T_EXIT;

trait UnparseableTokensTrait
{
    /**
     * Tokens that, when they occur in a config file, make it impossible for us
     * to include it in order to aggregate configuration.
     *
     * @var int[]
     */
    private $unparseableTokens = [
        T_EVAL,
        T_EXIT,
    ];

    /**
     * @param string $packageConfigPath
     * @return bool
     */
    private function configFileNeedsParsing($packageConfigPath)
    {
        $contents = file_get_contents($packageConfigPath);
        if (preg_match('/[\'"]asset_manager[\'"]\s*\=\>/s', $contents)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $packageConfigPath
     * @return bool
     */
    private function isParseableContent($packageConfigPath)
    {
        $contents = file_get_contents($packageConfigPath);
        $tokens   = token_get_all($contents);
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], $this->unparseableTokens, true)) {
                return false;
            }
        }
        return true;
    }
}
