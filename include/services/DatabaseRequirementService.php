<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Database Requirement Service
 *
 * Identifies the connected MySQL or MariaDB server and checks it against the
 * minimum versions declared in version.php.
 *
 * @package    LumoraGallery
 * @subpackage Database
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.22.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class DatabaseRequirementService
{
    /**
     * Normalize a raw server version string.
     *
     * PDO reports MariaDB as e.g. "5.5.5-10.11.6-MariaDB" (replication prefix)
     * or "10.11.6-MariaDB-1:10.11.6+maria~ubu2204". Anything not mentioning
     * MariaDB, including forks such as Percona, is treated as MySQL.
     *
     * @return array{engine: string, version: string, raw: string, parsed: bool}
     */
    public static function parse(string $raw): array
    {
        $engine  = stripos($raw, 'mariadb') !== false ? 'mariadb' : 'mysql';
        $numeric = $engine === 'mariadb' ? (string) preg_replace('/^5\.5\.5-/', '', $raw) : $raw;

        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $numeric, $m)) {
            return ['engine' => $engine, 'version' => '', 'raw' => $raw, 'parsed' => false];
        }

        return [
            'engine'  => $engine,
            'version' => $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0'),
            'raw'     => $raw,
            'parsed'  => true,
        ];
    }

    /**
     * Identify the server behind the current LumoraDB connection.
     *
     * @return array{engine: string, version: string, raw: string, parsed: bool}
     */
    public static function serverInfo(): array
    {
        return self::parse((string) LumoraDB::pdo()->getAttribute(PDO::ATTR_SERVER_VERSION));
    }

    /**
     * Whether the server meets the minimum for its engine.
     *
     * @param array{engine: string, version: string, raw: string, parsed: bool} $info
     * @return bool|null Null when the version could not be parsed.
     */
    public static function meetsMinimum(array $info, string $minMysql, string $minMariadb): ?bool
    {
        if (!$info['parsed']) {
            return null;
        }

        $min = $info['engine'] === 'mariadb' ? $minMariadb : $minMysql;
        return version_compare($info['version'], $min, '>=');
    }

    /** Same check against the minimums this release declares in version.php. */
    public static function meetsCurrentMinimum(array $info): ?bool
    {
        return self::meetsMinimum($info, LUMORA_MIN_MYSQL, LUMORA_MIN_MARIADB);
    }

    /** @param array{engine: string, version: string, raw: string, parsed: bool} $info */
    public static function describe(array $info): string
    {
        if (!$info['parsed']) {
            return $info['raw'] !== '' ? $info['raw'] : 'Unknown';
        }
        return ($info['engine'] === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $info['version'];
    }

    /** "5.6.0" becomes "5.6", "10.0.5" stays "10.0.5". */
    public static function shortVersion(string $version): string
    {
        return (string) preg_replace('/\.0$/', '', $version);
    }

    /**
     * Plain-language refusal naming the detected server and both minimums.
     *
     * @param array{engine: string, version: string, raw: string, parsed: bool} $info
     */
    public static function unsupportedMessage(array $info, string $minMysql, string $minMariadb): string
    {
        return 'Your database server is ' . self::describe($info) . '. Lumora Gallery needs MySQL '
            . self::shortVersion($minMysql) . ' or newer, or MariaDB ' . self::shortVersion($minMariadb)
            . ' or newer. Ask your host to upgrade, or choose a newer database server in your hosting control panel.';
    }

    /**
     * Read the database minimums a release package declares in its version.php.
     *
     * @return array{mysql: string|null, mariadb: string|null}
     */
    public static function extractMinimums(string $versionPhpContent): array
    {
        $read = static function (string $constant) use ($versionPhpContent): ?string {
            $pattern = "/define\\(\\s*'" . $constant . "'\\s*,\\s*'([0-9]+(?:\\.[0-9]+)*)'\\s*\\)/";
            return preg_match($pattern, $versionPhpContent, $m) ? $m[1] : null;
        };

        return ['mysql' => $read('LUMORA_MIN_MYSQL'), 'mariadb' => $read('LUMORA_MIN_MARIADB')];
    }
}
