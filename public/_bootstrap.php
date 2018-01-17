<?php

use Xpcoin\BlockFileWalker\Config;
use function Xpcoin\BlockFileWalker\toInt;
use function Xpcoin\BlockFileWalker\toAmount;


$dir = dirname(__DIR__);
chdir($dir);


require_once  "$dir/vendor/autoload.php";

Config::set("$dir/config.ini");

$q = null;
if (isset($_GET['q']))
    $q = $_GET['q'];

$app = new Xpcoin\BlockFileWalker\App($dir);
//$app->run($q)->show('public', 'cache');
$params = $app->run($q)->getParams();





function blocksView($blocks){
    foreach ($blocks as $block){
        $o = new \stdClass;
        $p = $block->toPresenter();
        $cols = [
            'hash', 'nHeight', 'nMint', 'nMoneySupply', 'nFlags',
            'nVersion', 'hashNext', 'hashPrev', 'nTime', 'nBits', 'nNonce',
            'nStakeModifier',
            'prevoutStake.hash', 'prevoutStake.n', 'nStakeTime',
            'hashProofOfStake',
        ];
        $o->data = new \stdClass();
        foreach ($cols as $col){
            if (isset($p->$col))
                $o->data->$col = $p->$col;
        }
        $o->isCoinStake = $p->details->isCoinStake;
        if ($o->isCoinStake)
            $o->minedCss = 'staked';
        else
            $o->minedCss = 'mined';

        $o->txs = $p->details->vtx;

        yield $o;
    }
}

function txsView($txs){
    foreach ($txs as $tx){
        $o = new \stdClass;
        $o->blockhash = $tx->toPresenter()->blockhash;
        $tx->values['details']->readNextVin();
        $p = $tx->values['details']->toPresenter();
        $cols = [
            'txid', 'nVersion', 'nTime',
            'prevout.hash', 'prevout.n',
        ];
        $o->data = new \stdClass();
        foreach ($cols as $col){
            if (isset($p->$col))
                $o->data->$col = $p->$col;
        }

        // TODO: refactoring, move to presenter
        if (isset($p->vin['coinbase'])){
            $o->data->coinbase = $p->vin['coinbase'];
            $o->data->nSequence = toInt($p->vin['nSequence']);
        }else{
            $o->vin = [];
            foreach ($p->vin as $in){
                $obj = new \stdClass();
                $obj->prevoutHash = bin2hex($in['prevout.hash']);
                $obj->prevoutN = toInt($in['prevout.n']);
                $obj->scriptSig = $in['scriptSig'];
                $obj->nSequence = toInt($in['nSequence']);
                $o->vin[] = $obj;
            }

        }

        $o->vout = [];
        foreach ($p->vout as $out){
            $obj = new \stdClass();
            $obj->nValue = toAmount($out['nValue']);
            $obj->scriptPubKey = $out['scriptPubKey']->toString();

            $obj->addrs = [];
            $dests = $out['scriptPubKey']->extractDestinations();
            if ($dests){
                $obj->type = $dests[0];
                $obj->addrs = $dests[1];
            }

            if (strlen($out['nextin.hash'])){
                $obj->nextHash = bin2hex($out['nextin.hash']);
                $obj->nextN = toInt($out['nextin.n']);
            }

            $o->vout[] = $obj;
        }

        yield $o;
    }
}

$params->blocks = blocksView($params->blocks);
$params->txs = txsView($params->txs);

$app->show('public', 'cache', $params);
