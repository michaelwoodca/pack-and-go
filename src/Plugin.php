<?php

declare(strict_types=1);

namespace NoTrouble\PackAndGo;

defined('ABSPATH') || exit;

use NoTrouble\PackAndGo\Admin\Assets;
use NoTrouble\PackAndGo\Admin\ConnectPage;
use NoTrouble\PackAndGo\Admin\MappingPage;
use NoTrouble\PackAndGo\Admin\SyncController;
use NoTrouble\PackAndGo\Api\Client;
use NoTrouble\PackAndGo\Content\ContentCleaner;
use NoTrouble\PackAndGo\Discovery\PostTypeDiscovery;
use NoTrouble\PackAndGo\Mapping\MappingStore;
use NoTrouble\PackAndGo\Mapping\MappingSuggester;
use NoTrouble\PackAndGo\Mapping\TargetCatalog;
use NoTrouble\PackAndGo\OAuth\ConnectionStore;
use NoTrouble\PackAndGo\OAuth\PkceConnection;
use NoTrouble\PackAndGo\Preview\PostSampler;
use NoTrouble\PackAndGo\Support\Endpoints;
use NoTrouble\PackAndGo\Support\Settings;
use NoTrouble\PackAndGo\Sync\SyncLedger;
use NoTrouble\PackAndGo\Sync\SyncRunner;

final class Plugin
{
    private static ?self $instance = null;

    private ?Endpoints $endpoints = null;

    private ?ConnectionStore $store = null;

    private ?PkceConnection $connection = null;

    private ?Client $client = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        load_plugin_textdomain('pack-and-go', false, dirname(plugin_basename(PLUGIN_FILE)) . '/languages');

        if (is_admin()) {
            (new Assets())->register();
            (new ConnectPage($this))->register();
            (new MappingPage($this))->register();
            (new SyncController($this))->register();
        }
    }

    public function endpoints(): Endpoints
    {
        return $this->endpoints ??= new Endpoints();
    }

    public function store(): ConnectionStore
    {
        return $this->store ??= new ConnectionStore();
    }

    public function connection(): PkceConnection
    {
        return $this->connection ??= new PkceConnection($this->endpoints(), $this->store());
    }

    public function client(): Client
    {
        return $this->client ??= new Client($this->endpoints(), $this->store(), $this->connection());
    }

    public function discovery(): PostTypeDiscovery
    {
        return new PostTypeDiscovery();
    }

    public function targetCatalog(): TargetCatalog
    {
        return new TargetCatalog();
    }

    public function suggester(): MappingSuggester
    {
        return new MappingSuggester();
    }

    public function mappingStore(): MappingStore
    {
        return new MappingStore();
    }

    public function sampler(): PostSampler
    {
        return new PostSampler($this->contentCleaner());
    }

    public function contentCleaner(): ContentCleaner
    {
        return new ContentCleaner();
    }

    public function settings(): Settings
    {
        return new Settings();
    }

    public function syncRunner(): SyncRunner
    {
        return new SyncRunner($this);
    }

    public function ledger(): SyncLedger
    {
        return new SyncLedger((string) $this->store()->get('profile_id', ''));
    }
}
