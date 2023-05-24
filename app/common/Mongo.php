<?php

namespace app\common;

use MongoDB\Client;
use MongoDB\Driver\Exception\Exception;
use think\facade\Config;

class Mongo {
    private static $instance = null;
    private        $mongo    = null;

    public function __construct($mongoConfig = []) {
        if (!$mongoConfig) {
            $mongoConfig = Config::instance()->get("database.connections.mongodb");
        }
        $dsn         = sprintf("mongodb://%s:%s@%s:%s/%s",
            $mongoConfig["username"],
            $mongoConfig["password"],
            $mongoConfig["hostname"],
            $mongoConfig["hostport"],
            $mongoConfig["database"]
        );
        $this->mongo = new Client($dsn);
    }

    public static function instance($config = []): ?Mongo {
        self::$instance || self::$instance = new self($config);
        return self::$instance;
    }


    /**
     * @throws Exception
     */
    public function query(array $condition, array $option, $collection): array {
        $collection = $this->mongo->xhprof->{$collection};
        return $collection->find($condition, $option)->toArray();
    }


    /**
     * @throws Exception
     */
    public function queryOne(array $condition, array $option, $collection): object {
        $collection = $this->mongo->xhprof->{$collection};
        return $collection->findOne($condition, $option);
    }

    /**
     * @throws Exception
     */
    public function count(array $condition = [], array $option = [], $collection): int {
        $collection = $this->mongo->xhprof->{$collection};
        return $collection->countDocuments($condition);
    }

    public function insert(array $data, string $collectionName) {
        $collection = $this->mongo->xhprof->{$collectionName};
        return $collection->insertOne($data)->getInsertedId();
    }
}