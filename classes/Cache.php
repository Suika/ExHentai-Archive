<?php

class Cache
{
    private static $instance;
    private $memcache;
    private $connected;
    private $isMemcached = false;

    protected function __construct()
    {
        $this->connected = false;

        if (class_exists('Memcached') || class_exists('Memcache') || class_exists('MemcachePool')) {
            $config = Config::get();
            if (!empty($config->memcache)) {
                if (class_exists('Memcached')) {
                    // ext-memcached (PHP 8.5 compatible); values are compressed by default
                    $memcache = new Memcached();
                    $this->isMemcached = true;
                } elseif (class_exists('MemcachePool')) {
                    $memcache = new MemcachePool();
                } else {
                    $memcache = new Memcache();
                }
               
                $this->connected = $memcache->addServer($config->memcache->host, $config->memcache->port);

                if ($this->connected) {
                    $this->memcache = $memcache;
                }
            }
        }
    }

    public static function getInstance()
    {
        if (!(self::$instance instanceof self)) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    public function cacheConnected()
    {
        return $this->connected;
    }

    public function getObject($objectType, $objectId)
    {
        if ($this->connected) {
            $key = $this->createObjectKey($objectType, $objectId);
            $data = $this->memcache->get($key);
            if ($data) {
                return $data;
            } else {
                return false;
            }
        }
    }

    public function setObject($objectType, $objectId, $data)
    {
        if ($this->connected) {
            $key = $this->createObjectKey($objectType, $objectId);
            if ($this->isMemcached) {
                $this->memcache->set($key, $data, 0);
            } else {
                $this->memcache->set($key, $data, MEMCACHE_COMPRESSED, 0);
            }
        }
    }

    public function deleteObject($objectType, $objectId)
    {
        if ($this->connected) {
            $key = $this->createObjectKey($objectType, $objectId);
            $this->memcache->delete($key);
        }
    }

    public function flush()
    {
        if ($this->connected) {
            return $this->memcache->flush();
        }
    }

    public function createObjectKey($objectType, $objectId)
    {
        return sprintf("%s_%d", $objectType, $objectId);
    }

    public function __destruct()
    {
        if ($this->connected) {
            if ($this->isMemcached) {
                $this->memcache->quit();
            } else {
                $this->memcache->close();
            }
        }
    }
}
