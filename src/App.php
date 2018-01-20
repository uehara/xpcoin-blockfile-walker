<?php

namespace Xpcoin\BlockFileWalker;

use Laiz\Template\Parser;
use function Xpcoin\BlockFileWalker\addrToBin;
use function Xpcoin\BlockFileWalker\toByteaDb;

use PDO;

class App
{
    private $rootdir;
    private $params;
    private $db;

    public function __construct($dir)
    {
        $this->rootdir = $dir;

        $this->params = new \stdClass;

        $this->db = new Db(Config::$datadir);
    }

    public function run($query = null, $full = null)
    {
        $p = $this->params;
        $p->query = $query;
        $p->address = null;

        if ($query === null){
            $p->blocks = $this->queryHeight();
            $p->txs = [];
            return $this;
        }
        if (in_array($query[0], Config::$ADDRESS_PREFIX)){
            $p->address = $query;
            $p->blocks = [];
            $p->txs = $this->queryAddr($query, $full);
            if (!$full)
                $p->FULL_LINK = true;

            return $this;
        }

        $p->blocks = $this->query(
            Xp\DiskBlockIndex::class, packKey('blockindex', $query));
        $p->txs = $this->query(
            Xp\DiskTxPos::class, packKey('tx', $query));

        return $this;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function show($public, $cache, $params = null)
    {
        if ($params === null)
            $params = $this->params;

        $tmpl = new Parser($public, $cache);
        $tmpl->addBehavior('l',
                           'Xpcoin\BlockFileWalker\Presenter\Filter::toHashLink',
                           true);
        $tmpl->addBehavior('a',
                           'Xpcoin\BlockFileWalker\Presenter\Filter::toAmount');
        $tmpl->setFile('index.html')->show($params);
    }


    private function query($cls, $query)
    {
        $f = [$cls, 'fromBinary'];
        foreach ($this->db->range($query) as $key => $value){
            $row = $f($key, $value);
            yield $key => $row;
        }
    }

    private function queryHeight($limit = 100)
    {
        $pdo = Config::getPdo();
        $sql = 'select bhash from bindex order by height desc limit ' . $limit;

        $prefix = packStr('blockindex');

        $stmt = $pdo->prepare($sql);
        $stmt->bindColumn(1, $bhash);
        $stmt->execute();
        while ($_ = $stmt->fetch(PDO::FETCH_BOUND)){
            $q = $prefix . $bhash;
            foreach ($this->db->range($q) as $key => $value){
                $block = Xp\DiskBlockIndex::fromBinary($key, $value);
                yield $block;
            }
        }
    }


    private function queryAddr($query, $full, $limit = 200)
    {
        $pdo = Config::getPdo();

        $addr = toByteaDb(strrev(addrToBin($query)));

        if ($full){
            $sql = '
select txhash from txindex
where outaddr @> ARRAY[?::bytea] or inaddr @> ARRAY[?::bytea]
order by height desc
limit ' . $limit;
        }else{
        $sql = '
select txhash from txindex
where outaddr @> ARRAY[?::bytea]
  and nexthash[array_position(outaddr, ?::bytea)] is null
order by height desc
limit ' . $limit;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->bindColumn(1, $txhash);
        $stmt->bindParam(1, $addr, PDO::PARAM_LOB);
        $stmt->bindParam(2, $addr, PDO::PARAM_LOB);
        $stmt->execute();

        $prefix = packStr('tx');
        $inOrOut = [];
        while ($_ = $stmt->fetch(PDO::FETCH_BOUND)){
            if (isset($inOrOut[$txhash]))
                continue;
            $inOrOut[$txhash] = true;

            $q = $prefix . $txhash;
            foreach ($this->db->range($q) as $key => $value){
                $tx = Xp\DiskTxPos::fromBinary($key, $value);
                yield $tx;
            }
        }
    }
}
