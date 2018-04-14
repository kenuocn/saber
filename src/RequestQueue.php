<?php
/**
 * Copyright: Swlib
 * Author: Twosee <twose@qq.com>
 * Date: 2018/4/1 下午3:47
 */

namespace Swlib\Saber;

class RequestQueue extends \SplQueue
{
    /** @var \SplQueue */
    public $concurrency_pool;

    public $max_concurrency = -1;

    public function enqueue($request)
    {
        if (!($request instanceof Request)) {
            throw new \InvalidArgumentException('Value must be instance of ' . Request::class);
        }
        if ($this->getMaxConcurrency() > 0) {
            if ($request->isWaiting()) {
                throw new \InvalidArgumentException("You can't enqueue a waiting request when using the max concurrency control!");
            }
        }
        /**
         * 注意! `withRedirectWait`是并发重定向优化
         * 原理是重定向时并不如同单个请求一样马上收包,而是将其再次加入请求队列执行defer等待收包
         * 待队列中所有原有并发请求第一次收包完毕后,再一同执行重定向收包,
         * 否则并发请求会由于重定向退化为队列请求,可以自行测试验证
         *
         * Notice! `withRedirectWait` is a concurrent redirection optimization
         * The principle is that instead of receiving a packet as soon as a single request is,
         * it is added to the request queue again and delayed to wait for the packet to recv.
         * After all the original concurrent requests in the queue for the first time are recved, the redirect requests recv again.
         * Otherwise, the concurrent request can be degraded to a queue request due to redirection, you can be tested and verified.
         */
        parent::enqueue($request->withRedirectWait(true));
    }

    public function getMaxConcurrency(): int
    {
        return $this->max_concurrency;
    }

    public function withMaxConcurrency(int $num = -1): self
    {
        $this->max_concurrency = $num;

        return $this;
    }

    /**
     * @return ResponseMap|Response[]
     */
    public function recv(): ResponseMap
    {
        $start_time = microtime(true);
        $res_map = new ResponseMap(); //Result-set
        $index = 0;

        $max_co = $this->getMaxConcurrency();
        if ($max_co > 0 && $max_co < $this->count()) {
            if (!isset($this->concurrency_pool) || !$this->concurrency_pool->isEmpty()) {
                $this->concurrency_pool = new \SplQueue();
            }
            while (!$this->isEmpty()) {
                $current_co = 0;
                while (!$this->isEmpty() && $max_co > $current_co++) {
                    $req = $this->dequeue();
                    /** @var $req Request */
                    if (!$req->isWaiting()) {
                        $req->exec();
                    } elseif ($max_co > 0) {
                        throw new \InvalidArgumentException("The waiting request is forbidden when using the max concurrency control!");
                    }
                    $this->concurrency_pool->enqueue($req);
                }
                while (!$this->concurrency_pool->isEmpty()) {
                    $req = $this->concurrency_pool->dequeue();
                    /** @var $req Request */
                    $res = $req->recv();
                    if ($res instanceof Request) {
                        $this->concurrency_pool->enqueue($res);
                    } else {
                        //response create
                        $res_map[$index] = $res;
                        if (($name = $req->getName()) && !isset($res_map[$name])) {
                            $res_map[$name] = &$res_map[$index];
                        }
                        $index++;
                    }
                }
            }
        } else {
            foreach ($this as $req) {
                $req->exec();
            }
            while (!$this->isEmpty()) {
                $req = $this->dequeue();
                /** @var $req Request */
                $res = $req->recv();
                if ($res instanceof Request) {
                    $this->enqueue($res);
                } else {
                    //response create
                    $res_map[$index] = $res;
                    if (($name = $req->getName()) && !isset($res_map[$name])) {
                        $res_map[$name] = &$res_map[$index];
                    }
                    $index++;
                }
            }
        }
        $res_map->time = microtime(true) - $start_time;

        return $res_map;
    }

}