<?php

/*
 * Rebuilds resources/networks.php, the snapshot shipped with the extension,
 * from the same sources `php flarum bouncer:networks` uses. Run before a
 * release: php tools/build-networks.php
 */

use ErnestDefoe\Bouncer\Network\Networks;
use ErnestDefoe\Bouncer\Network\Updater;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;

require __DIR__.'/../vendor/autoload.php';

$settings = new Settings(new class implements SettingsRepositoryInterface {
    public function all(): array
    {
        return [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value): void
    {
    }

    public function delete(string $keyLike): void
    {
    }
});

$tmp = sys_get_temp_dir().'/bouncer-build-'.getmypid();
$networks = new Networks(new Paths(['base' => $tmp, 'public' => $tmp, 'storage' => $tmp, 'vendor' => $tmp]));
$result = (new Updater($settings, $networks))->update();

rename($networks->file(), Networks::BUNDLED);
@rmdir(dirname($networks->file()));
@rmdir($tmp);

echo json_encode($result), "\n";
