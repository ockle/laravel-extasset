<?php

namespace Ockle\Extasset;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Logging\Log;
use Psr\Http\Message\ResponseInterface;

class Extasset
{
    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var CacheManager
     */
    protected $cache;

    /**
     * @var Filesystem
     */
    protected $disk;

    /**
     * @var Log
     */
    protected $log;

    /**
     * Extasset constructor
     *
     * @param Repository $config
     * @param CacheManager $cache
     * @param Factory $files
     * @param Log $log
     */
    public function __construct(Repository $config, CacheManager $cache, Factory $files, Log $log)
    {
        $this->config = $config;
        $this->cache = $cache;
        $this->disk = $files->disk($this->config->get('extasset.disk'));
        $this->log = $log;
    }

    /**
     * Return the current URL for an asset
     *
     * @param string $assetName
     * @return string
     */
    public function url($assetName)
    {
        $cacheKey = $this->getCacheKey($assetName);

        // If we don't have a record of this file in cache
        // e.g. if it hasn't been handled by an update,
        // then fall back to serving the external URL
        if (!$this->cache->has($cacheKey)) {
            return $this->has($assetName) ? $this->config->get('extasset.assets')[$assetName]['source'] : '';
        }

        $hash = $this->cache->get($cacheKey)['hash'];

        // Otherwise return the URL to the asset,
        // ready for dropping into a src or href
        return $this->disk->url($this->getFileName($assetName, $hash));
    }

    /**
     * Does Extasset know about this asset?
     *
     * @param string $assetName
     * @return bool
     */
    public function has($assetName)
    {
        return array_key_exists($assetName, $this->config->get('extasset.assets'));
    }

    /**
     * Check and update all assets from sources
     *
     * @param Client $client
     * @param bool $force
     */
    public function update(Client $client, $force = false)
    {
        $now = Carbon::now();

        // We'll do the fetching of the assets synchronously,
        // so create a generator
        $requests = function () use ($client, $now, $force) {
            foreach ($this->config->get('extasset.assets') as $assetName => $assetData) {
                $cacheKey = $this->getCacheKey($assetName);

                // If the user has defined a check_interval for the asset and we are
                // running a check before the last cached data has expired,
                // then don't bother fetching it again
                if (!$force && isset($assetData['check_interval']) && $this->cache->has($cacheKey) && ($this->cache->get($cacheKey)['timestamp'] + ($assetData['check_interval'] * 60) > $now->timestamp)) {
                    continue;
                }

                yield $assetName => function () use ($client, $assetData) {
                    return $client->getAsync($assetData['source']);
                };
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $this->config->get('extasset.concurrency', 5),
            'fulfilled' => function (ResponseInterface $response, $assetName) use ($now, $force) {
                // Get the contents and calculate the hash, which is what
                // we'll use as the cache-busting identifier
                $contents = $response->getBody()->getContents();
                $hash = md5($contents);

                $cacheKey = $this->getCacheKey($assetName);

                // Check if we've cached this before
                if ($this->cache->has($cacheKey)) {
                    $oldHash = $this->cache->get($cacheKey)['hash'];

                    // If we have and the file contents have not changed,
                    // then update the timestamp and we're done
                    if (!$force && ($hash === $oldHash)) {
                        $this->cache->forever($cacheKey, [
                            'hash' => $hash,
                            'timestamp' => $now->timestamp,
                        ]);

                        return;
                    } elseif ($hash !== $oldHash) {
                        // If the contents have changed, mark the old one to be removed
                        $fileToRemove = $this->getFileName($assetName, $oldHash);
                    }
                }

                $this->disk->put($this->getFileName($assetName, $hash), $contents);

                $this->cache->forever($cacheKey, [
                    'hash' => $hash,
                    'timestamp' => $now->timestamp,
                ]);

                // Only remove the old one after the new one is in place,
                // otherwise a request could come in between the old one
                // being removed and the new one being stored, and there
                // would be no file to serve them.
                if (isset($fileToRemove)) {
                    $this->disk->delete($fileToRemove);
                }
            },
            'rejected' => function ($reason, $assetName) {
                $context = ['asset' => $assetName];

                if ($reason instanceof RequestException) {
                    $response = $reason->getResponse();

                    $context['status'] = $response->getStatusCode();
                    $context['body'] = $response->getBody()->getContents();
                }

                $this->log->error('Extasset update failure', $context);
            }
        ]);

        $pool->promise()->wait();
    }

    /**
     * Format file name for currently cached asset
     *
     * @param string $assetName
     * @param string $hash
     * @return string
     */
    protected function getFileName($assetName, $hash)
    {
        return "$hash.$assetName";
    }

    /**
     * Get cache storage key for asset
     *
     * @param string $assetName
     * @return string
     */
    protected function getCacheKey($assetName)
    {
        return "extasset.assets.$assetName";
    }
}
