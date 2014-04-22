<?php
/**
 * Opine\CollectionRoute
 *
 * Copyright (c)2013, 2014 Ryan Mahoney, https://github.com/Opine-Org <ryan@virtuecenter.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
namespace Opine;

class CollectionRoute {
    public $cache = false;
    private $separation;
    private $route;
    private $db;
    private $response;

    public function __construct ($collection, $route, $db, $separation, $response) {
        $this->route = $route;
        $this->db = $db;
        $this->separation = $separation;
        $this->response = $response;
        $this->collection = $collection;
    }

    public function cacheSet ($cache) {
        $this->cache = $cache;
    }

    public function json ($root, $prefix='') {
        $callback = function ($collection, $method='all', $limit=20, $page=1, $sort=[], $fields=[]) {
            if (in_array($method, ['byId', 'bySlug'])) {
                $value = $limit;
            } else {
                $value = false;
                if (substr_count($method, '-') > 0) {
                    list($method, $value) = explode('-', urldecode($method), 2);
                }
            }
            if ($page == 0) {
                $page = 1;
            }
            $collectionObj = $this->collection->factory($collection, $limit, $page, $sort);
            if (!method_exists($collectionObj, $method)) {
                exit ($method . ': unknown method.');
            }
            $head = '';
            $tail = '';
            if (isset($_GET['callback'])) {
                if ($_GET['callback'] == '?') {
                    $_GET['callback'] = 'callback';
                }
                $head = $_GET['callback'] . '(';
                $tail = ');';
            }
            $options = null;
            $data = $collectionObj->$method($value);
            $name = $collectionObj->collection();
            if ($method == 'byEmbeddedField') {
                $name = $collectionObj->name;
            }
            if (isset($_GET['pretty'])) {
                $options = JSON_PRETTY_PRINT;
                $head = '<html><head></head><body style="margin:0; border:0; padding: 0"><textarea wrap="off" style="overflow: auto; margin:0; border:0; padding: 0; width:100%; height: 100%">';
                $tail = '</textarea></body></html>';
            }
            if (in_array($method, ['byId', 'bySlug'])) {
                $name = $collectionObj->singular;
                echo $head . json_encode([
                    $name => $data
                ], $options) . $tail;
            } else {
                echo $head . json_encode([
                    $name => $data,
                    'pagination' => [
                        'limit' => $limit,
                        'total' => $collectionObj->totalGet(),
                        'page' => $page,
                        'pageCount' => ceil($collectionObj->totalGet() / $limit)
                    ],
                    'metadata' => array_merge(['display' => [
                            'collection' => ucwords(str_replace('_', ' ', $collection)),
                            'document' => ucwords(str_replace('_', ' ', $collectionObj->singular)),
                        ],
                        'method' => $method
                    ], get_object_vars($collectionObj))
                ], $options) . $tail;
            }
        };
        $this->route->get($prefix . '/json-data/{collection}', $callback);
        $this->route->get($prefix . '/json-data/{collection}/{method}', $callback);
        $this->route->get($prefix . '/json-data/{collection}/{method}/{limit}', $callback);
        $this->route->get($prefix . '/json-data/{collection}/{method}/{limit}/{page}', $callback);
        $this->route->get($prefix . '/json-data/{collection}/{method}/{limit}/{page}/{sort}', $callback);
        $this->route->get($prefix . '/json-data/{collection}/{method}/{limit}/{page}/{sort}/{fields}', $callback);

        $this->route->get($prefix . '/json-collections', function () use ($root) {
            if (!empty($this->cache)) {
                $collections = $this->cache;
            } else {
                $cacheFile = $root . '/../collections/cache.json';
                if (!file_exists($cacheFile)) {
                    return;
                }
                $collections = (array)json_decode(file_get_contents($cacheFile), true);
            }
            if (!is_array($collections)) {
                return;
            }
            foreach ($collections as &$collection) {
                $collectionObj = $this->collection->factory($collection['p']);
                $reflection = new \ReflectionClass($collectionObj);
                $methods = $reflection->getMethods();
                foreach ($methods as $method) {
                    if (in_array($method->name, ['document','__construct','totalGet','localSet','decorate','fetchAll'])) {
                        continue;
                    }
                    $collection['methods'][] = $method->name;
                }
            }
            $head = '';
            $tail = '';
            if (isset($_GET['callback'])) {
                if ($_GET['callback'] == '?') {
                    $_GET['callback'] = 'callback';
                }
                $head = $_GET['callback'] . '(';
                $tail = ');';
            }
            echo $head . json_encode($collections) . $tail;
        });
    }

    public function app ($root) {
        if (!empty($this->cache)) {
            $collections = $this->cache;
        } else {
            $cacheFile = $root . '/../collections/cache.json';
            if (!file_exists($cacheFile)) {
                return;
            }
            $collections = (array)json_decode(file_get_contents($cacheFile), true);
        }
        if (!is_array($collections)) {
            return;
        }
        $routed = [];
        foreach ($collections as $collection) {
            $callbackList = function ($method='all', $limit=null, $page=1, $sort=[]) use ($collection) {
                if ($limit === null) {
                    if (isset($collection['limit'])) {
                        $limit = $collection['limit'];
                    } else {
                        $limit = 10;
                    }
                }
                $args = [];
                if ($limit != null) {
                    $args['limit'] = $limit;
                }
                $args['method'] = $method;
                $args['page'] = $page;
                $args['sort'] = json_encode($sort);
                foreach (['limit', 'page', 'sort'] as $option) {
                    $key = $collection['p'] . '-' . $method . '-' . $option;
                    if (isset($_GET[$key])) {
                        $args[$option] = $_GET[$key];
                    }
                }
                $this->separation->app()->layout('collections/' . $collection['p'])->args($collection['p'], $args)->template()->write();
            };
            $callbackSingle = function ($slug) use ($collection) {
                $this->separation->app()->layout('documents/' . $collection['s'])->args($collection['s'], ['slug' => basename($slug, '.html')])->template()->write($this->response->body);
            };
            if (isset($collection['p']) && !isset($routed[$collection['p']])) {                    
                $this->route->get('/' . $collection['p'], $callbackList);
                $this->route->get('/' . $collection['p'] . '/{method}', $callbackList);
                $this->route->get('/' . $collection['p'] . '/{method}/{limit}', $callbackList);
                $this->route->get('/' . $collection['p'] . '/{method}/{limit}/{page}', $callbackList);
                $this->route->get('/' . $collection['p'] . '/{method}/{limit}/{page}/{sort}', $callbackList);
                $routed[$collection['p']] =  true;
            }
            if (!isset($collection['s']) || isset($routed[$collection['s']])) {
                continue;
            }
            $this->route->get('/' . $collection['s'] . '/{slug}', $callbackSingle);
            $this->route->get('/' . $collection['s'] . '/id/{id}', $callbackSingle);
            $routed[$collection['s']] =  true;
        }
    }

    public function build ($root, $url) {
        $cache = [];
        $dirFiles = glob($root . '/../collections/*.php');
        foreach ($dirFiles as $collection) {
            require_once($collection);
            $collection = basename($collection, '.php');
            $className = 'Collection\\' . $collection;
            $instance = new $className();
            $cache[] = [
                'p' => $collection,
                's' => $instance->singular
            ];
        }
        $json = json_encode($cache, JSON_PRETTY_PRINT);
        file_put_contents($root . '/../collections/cache.json', $json);
        foreach ($cache as $collection) {
            $filename = $root . '/layouts/collections/' . $collection['p'] . '.html';
            if (!file_exists($filename)) {
                file_put_contents($filename, self::stubRead('layout-collection.html', $collection, $url, $root));
            }
            $filename = $root . '/partials/collections/' . $collection['p'] . '.hbs';
            if (!file_exists($filename)) {
                file_put_contents($filename, self::stubRead('partial-collection.hbs', $collection, $url, $root));
            }
            $filename = $root . '/layouts/documents/' . $collection['s'] . '.html';
            if (!file_exists($filename)) {
                file_put_contents($filename, self::stubRead('layout-document.html', $collection, $url, $root));
            }
            $filename = $root . '/partials/documents/' . $collection['s'] . '.hbs';
            if (!file_exists($filename)) {
                file_put_contents($filename, self::stubRead('partial-document.hbs', $collection, $url, $root));
            }
            $filename = $root . '/../app/collections/' . $collection['p'] . '.yml';
            if (!file_exists($filename)) {
                file_put_contents($filename, self::stubRead('app-collection.yml', $collection, $url, $root));
            }
            $filename = $root . '/../app/documents/' . $collection['s'] . '.yml';
            if (!file_exists($filename)) {
                file_put_contents($filename, self::stubRead('app-document.yml', $collection, $url, $root));
            }
        }
        return $json;
    }

    private static function stubRead ($name, &$collection, $url, $root) {
        $data = file_get_contents($root . '/../vendor/opine/build/static/' . $name);
        return str_replace(['{{$url}}', '{{$plural}}', '{{$singular}}'], [$url, $collection['p'], $collection['s']], $data);
    }

    public function collectionList ($root) {
        $this->route->get('/collections', function () use ($root) {
            $collections = (array)json_decode(file_get_contents($root . '/../collections/cache.json'), true);
            echo '<html><body>';
            foreach ($collections as $collection) {
                echo '<a href="/json-data/' . $collection['p'] . '/all?pretty">', $collection['p'], '</a><br />';
            }
            echo '</body></html>';
            exit;
        })->name('collections');
    }

    public function upgrade ($root) {
        $manifest = (array)json_decode(file_get_contents('https://raw.github.com/Opine-Org/Collection/master/available/manifest.json'), true);
        $upgraded = 0;
        foreach (glob($root . '/../collections/*.php') as $filename) {
            $lines = file($filename);
            $version = false;
            $mode = false;
            $link = false;
            foreach ($lines as $line) {
                if (substr_count($line, ' * @') != 1) {
                    continue;
                }
                if (substr_count($line, '* @mode') == 1) {
                    $mode = trim(str_replace('* @mode', '', $line));
                    continue;
                }
                if (substr_count($line, '* @version') == 1) {
                    $version = floatval(trim(str_replace('* @version', '', $line)));
                    continue;
                }
                if (substr_count($line, '* @link') == 1) {
                    $link = trim(str_replace('* @link', '', $line));
                    continue;
                }
            }
            if ($mode === false || $version === false || $link === false) {
                continue;
            }
            if ($version == '' || $link == '' || $mode == '') {
                continue;
            }
            if ($mode != 'upgrade') {
                continue;
            }
            if ($version == $manifest['collections'][basename($filename, '.php')]) {
                continue;
            }
            $newVersion = floatval($manifest['collections'][basename($filename, '.php')]);
            if ($newVersion > $version) {
                file_put_contents($filename, file_get_contents($link));
                echo 'Upgraded Collection: ', basename($filename, '.php'), ' to version: ', $newVersion, "\n";
                $upgraded++;
            }
        }
        echo 'Upgraded ', $upgraded, ' collections.', "\n";
    }
}
